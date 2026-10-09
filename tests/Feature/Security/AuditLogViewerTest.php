<?php

declare(strict_types=1);

use App\Domain\Security\Actions\AttemptLogin;
use App\Domain\Security\Actions\ChangeOwnPassword;
use App\Domain\Security\Actions\CreateUser;
use App\Domain\Security\Actions\DeactivateUser;
use App\Domain\Security\Actions\ReactivateUser;
use App\Domain\Security\Actions\ResetUserPassword;
use App\Domain\Security\Actions\UnlockUser;
use App\Domain\Security\Actions\UpdateRolePermissions;
use App\Domain\Security\Actions\UpdateSetting;
use App\Domain\Security\Actions\UpdateUserRoles;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\RoleCode;
use App\Livewire\Admin\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-009, FR-SEC-010, INV-18, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function viewerRows($component)
{
    return $component->viewData('logs');
}

it('lets the Owner and the Auditor open the viewer and refuses everyone else', function (): void {
    foreach ([RoleCode::Owner, RoleCode::Auditor] as $role) {
        $this->actingAs(Users::make('ok.'.strtolower($role->value), $role))->get(route('admin.audit'))->assertOk()->assertSee('Read-only');
    }

    foreach ([RoleCode::Administrator, RoleCode::Cashier, RoleCode::CollectionSupervisor, RoleCode::Technician, RoleCode::Viewer] as $role) {
        $user = Users::make('no.'.strtolower($role->value), $role);

        $this->actingAs($user)->get(route('admin.audit'))->assertForbidden();
        Livewire::actingAs($user)->test(AuditLogViewer::class)->assertForbidden();
    }
});

it('has no method that could edit or delete an audit row', function (): void {
    $class = new ReflectionClass(AuditLogViewer::class);

    // Methods written in this file only; the traits (pagination, authorize) come from vendor code.
    $own = collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->getFileName() === $class->getFileName())
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->sort()->values()->all();

    expect($own)->toBe(['apply', 'boot', 'clear', 'mount', 'render']);
});

it('shows rows newest first with who, what, why and the before and after values', function (): void {
    $auditor = Users::make('auditor', RoleCode::Auditor);
    $actor = Users::make('maria', RoleCode::Cashier);
    app(AuditLogger::class)->record('test.first', $actor, 'invoice', 7, 'Older entry');
    app(AuditLogger::class)->record('test.second', $actor, 'invoice', 7, 'Newer entry', ['status' => 'UNPAID'], ['status' => 'PAID']);

    $component = Livewire::actingAs($auditor)->test(AuditLogViewer::class);

    expect(viewerRows($component)->pluck('action')->take(2)->all())->toBe(['test.second', 'test.first']);
    $component->assertSeeInOrder(['test.second', 'test.first'])
        ->assertSee('Test maria')
        ->assertSee('invoice #7')
        ->assertSee('Newer entry')
        ->assertSee('UNPAID')
        ->assertSee('PAID');
});

it('filters by event, user, record type, request id and date', function (): void {
    $auditor = Users::make('auditor', RoleCode::Auditor);
    $maria = Users::make('maria', RoleCode::Cashier);
    $jose = Users::make('jose', RoleCode::Cashier);
    $logger = app(AuditLogger::class);

    $logger->record('test.alpha', $maria, 'invoice', 1);
    $logger->record('test.beta', $jose, 'payment', 2);
    // An older row, inserted with its own timestamp (rows can be added, never edited).
    DB::table('audit_logs')->insert([
        'action' => 'test.alpha', 'actor_user_id' => $jose->id, 'entity_type' => 'invoice', 'entity_id' => 3,
        'occurred_at' => now()->subDays(10), 'request_id' => 'req-old-0001',
    ]);

    $page = fn () => Livewire::actingAs($auditor)->test(AuditLogViewer::class);

    expect(viewerRows($page()->set('action', 'test.alpha')->call('apply'))->count())->toBe(2)
        ->and(viewerRows($page()->set('actorId', (string) $jose->id)->call('apply'))->count())->toBe(2)
        ->and(viewerRows($page()->set('entityType', 'payment')->call('apply'))->pluck('action')->all())->toBe(['test.beta'])
        ->and(viewerRows($page()->set('requestId', 'req-old-0001')->call('apply'))->pluck('entity_id')->all())->toBe([3])
        ->and(viewerRows($page()->set('from', now()->subDays(2)->toDateString())->call('apply'))->count())->toBe(2)
        ->and(viewerRows($page()->set('to', now()->subDays(5)->toDateString())->call('apply'))->pluck('entity_id')->all())->toBe([3])
        ->and(viewerRows($page()->set('action', 'test.alpha')->set('actorId', (string) $maria->id)->call('apply'))->pluck('entity_id')->all())->toBe([1]);

    // Clear puts everything back.
    expect(viewerRows($page()->set('action', 'test.beta')->call('apply')->call('clear'))->count())->toBe(3);
});

it('validates filters and treats a tampered value as plain text, never as SQL', function (): void {
    $auditor = Users::make('auditor', RoleCode::Auditor);
    app(AuditLogger::class)->record('test.alpha', $auditor);

    Livewire::actingAs($auditor)->test(AuditLogViewer::class)
        ->set('from', 'not-a-date')->call('apply')->assertHasErrors('from');

    Livewire::actingAs($auditor)->test(AuditLogViewer::class)
        ->set('from', '2026-02-10')->set('to', '2026-02-01')->call('apply')->assertHasErrors('to');

    // Text filters are bound as values, so injection text just matches nothing.
    $text = Livewire::actingAs($auditor)->test(AuditLogViewer::class)
        ->set('action', "x'; DROP TABLE audit_logs; --")
        ->set('requestId', "' OR '1'='1");
    expect(viewerRows($text)->count())->toBe(0);

    // A user id that is not purely digits is ignored rather than cast to a number.
    $actor = Livewire::actingAs($auditor)->test(AuditLogViewer::class)->set('actorId', '1 OR 1=1');
    expect(viewerRows($actor)->count())->toBe(1);

    expect(AuditLog::query()->count())->toBe(1);
});

it('pages through large logs 50 rows at a time', function (): void {
    $auditor = Users::make('auditor', RoleCode::Auditor);
    $now = now();
    DB::table('audit_logs')->insert(array_map(fn (int $i) => ['action' => 'test.bulk', 'occurred_at' => $now, 'entity_id' => $i], range(1, 120)));

    $component = Livewire::actingAs($auditor)->test(AuditLogViewer::class);

    expect(viewerRows($component)->count())->toBe(50);

    $component->call('nextPage');
    expect(viewerRows($component)->count())->toBe(50);

    $component->call('nextPage');
    expect(viewerRows($component)->count())->toBe(20);
});

it('keeps paging blocked after the permission is taken away', function (): void {
    $auditor = Users::make('auditor', RoleCode::Auditor);
    $page = Livewire::actingAs($auditor)->test(AuditLogViewer::class);

    Auth::setUser(Users::make('cashier', RoleCode::Cashier));

    $page->call('nextPage')->assertForbidden();
});

// ------------------------------------------------------------------ FR-SEC-010

it('records every kind of security event and the viewer can show each one (FR-SEC-010)', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $maria = Users::make('maria', RoleCode::Cashier);
    $roleId = fn (RoleCode $c) => Role::query()->where('code', $c->value)->firstOrFail()->id;
    $login = fn (string $password) => app(AttemptLogin::class)->handle('maria', $password, '127.0.0.1');
    $swallow = function (Closure $call): void {
        try {
            $call();
        } catch (LoginFailed|ValidationException) {
        }
    };

    // Sign-in, failures, lockout, owner unlock.
    foreach (range(1, 5) as $i) {
        $swallow(fn () => $login('wrong-guess-'.$i));
    }
    app(UnlockUser::class)->handle($owner, $maria->id, 'Verified by phone');
    $login(Users::PASSWORD);

    // Password change, good and bad.
    $swallow(fn () => app(ChangeOwnPassword::class)->handle($maria, 'not-my-password', 'a-brand-new-long-passphrase', 'a-brand-new-long-passphrase', '127.0.0.1'));
    app(ChangeOwnPassword::class)->handle($maria, Users::PASSWORD, 'a-brand-new-long-passphrase', 'a-brand-new-long-passphrase', '127.0.0.1');

    // Denied request, lock, unlock failure and success, logout (over HTTP).
    $this->actingAs($maria)->get(route('admin.users'))->assertForbidden();
    $session = ['security.last_activity' => now()->getTimestamp()];
    $this->actingAs($maria)->withSession($session)->postJson(route('session.lock'))->assertOk();
    $this->actingAs($maria)->withSession($session + ['security.locked' => true])->postJson(route('session.unlock'), ['password' => 'wrong-guess-x'])->assertStatus(422);
    $this->actingAs($maria)->withSession($session + ['security.locked' => true])->postJson(route('session.unlock'), ['password' => 'a-brand-new-long-passphrase'])->assertOk();
    $this->actingAs($maria)->withSession($session)->post(route('logout'))->assertRedirect();

    // Administration.
    $created = app(CreateUser::class)->handle($owner, 'new.hire', 'New Hire', [$roleId(RoleCode::Viewer)]);
    app(UpdateUserRoles::class)->handle($owner, $created->user->id, [$roleId(RoleCode::Cashier)], 'Moved to the counter');
    app(ResetUserPassword::class)->handle($owner, $created->user->id, 'Forgot password');
    app(DeactivateUser::class)->handle($owner, $created->user->id, 'Left the company');
    app(ReactivateUser::class)->handle($owner, $created->user->id, 'Came back');
    app(UpdateRolePermissions::class)->handle($owner, $roleId(RoleCode::Viewer), ['dashboard.view'], 'Narrow the viewer');
    app(UpdateSetting::class)->handle($owner, 'security.lockout_minutes', 20, 'Policy review');

    $recorded = AuditLog::query()->distinct()->pluck('action')->all();
    $expected = array_map(fn (AuditAction $a) => $a->value, AuditAction::cases());

    expect(array_values(array_diff($expected, $recorded)))->toBe([], 'Every audit event type must be produced by a real flow.');

    // And the viewer can list each one.
    $auditor = Users::make('auditor', RoleCode::Auditor);
    foreach ($expected as $action) {
        $page = Livewire::actingAs($auditor)->test(AuditLogViewer::class)->set('action', $action)->call('apply');

        expect(viewerRows($page)->count())->toBeGreaterThan(0, "The viewer shows no {$action} rows.");
    }
});

it('never stores a password in any audit row produced by these flows', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $created = app(CreateUser::class)->handle($owner, 'new.hire', 'New Hire', [Role::query()->where('code', 'VIEWER')->value('id')]);
    $reset = app(ResetUserPassword::class)->handle($owner, $created->user->id, 'Forgot password');

    $everything = json_encode(AuditLog::query()->get()->toArray());

    expect($everything)->not->toContain($created->temporaryPassword)->not->toContain($reset)->not->toContain(Users::PASSWORD);
});
