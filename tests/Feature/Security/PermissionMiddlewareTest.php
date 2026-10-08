<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

// FR-SEC-005, FR-SEC-006, FR-SEC-010, INV-17

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    Route::middleware(['web', 'auth', 'permission:user.manage'])->get('/_t/admin', fn () => 'admin ok')->name('t.admin');
    Route::middleware(['web', 'auth', 'permission:payment.view,report.export'])->get('/_t/both', fn () => 'both ok')->name('t.both');
    Route::middleware(['web', 'auth', 'permission:not.a.real.code'])->get('/_t/typo', fn () => 'x')->name('t.typo');
    Route::middleware(['web', 'permission:user.manage'])->get('/_t/noauth', fn () => 'x')->name('t.noauth');
    Route::middleware(['web', 'auth'])->get('/_t/gate', function () {
        Gate::authorize('user.manage');

        return 'gate ok';
    })->name('t.gate');
});

function loginAs(RoleCode $role, bool $active = true): User
{
    $user = new User(['username' => 'mw.'.uniqid(), 'full_name' => 'Middleware Test']);
    $user->forceFill(['password_hash' => Hash::make('throwaway'), 'is_active' => $active])->save();
    $user->roles()->attach(Role::query()->where('code', $role->value)->firstOrFail()->id);

    test()->actingAs($user);

    return $user;
}

it('lets a user with the permission through', function (): void {
    loginAs(RoleCode::Owner);

    $this->get('/_t/admin')->assertOk()->assertSee('admin ok');
    expect(AuditLog::query()->count())->toBe(0);
});

it('refuses a Cashier on an admin route with 403 and audits the refusal (AT-10)', function (): void {
    $cashier = loginAs(RoleCode::Cashier);

    $this->get('/_t/admin')->assertForbidden();

    $row = AuditLog::query()->sole();
    expect($row->action)->toBe('security.access_denied')
        ->and($row->actor_user_id)->toBe($cashier->id)
        ->and($row->new_values)->toMatchArray(['method' => 'GET', 'path' => '_t/admin', 'route' => 't.admin', 'permissions' => ['user.manage']]);
});

it('requires every listed permission', function (): void {
    loginAs(RoleCode::Cashier); // has payment.view, not report.export

    $this->get('/_t/both')->assertForbidden();

    loginAs(RoleCode::Auditor); // has both
    $this->get('/_t/both')->assertOk();
});

it('refuses an inactive user even if the role would allow it', function (): void {
    loginAs(RoleCode::Owner, active: false);

    $this->get('/_t/admin')->assertForbidden();
});

it('answers 401 when there is no logged-in user', function (): void {
    $this->get('/_t/noauth')->assertUnauthorized();
});

it('fails loudly on an unknown permission code instead of guessing', function (): void {
    loginAs(RoleCode::Owner);

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/_t/typo'))->toThrow(InvalidArgumentException::class, 'not.a.real.code');
});

it('audits a Gate refusal raised inside an action and still returns 403', function (): void {
    $cashier = loginAs(RoleCode::Cashier);

    $this->get('/_t/gate')->assertForbidden();

    $row = AuditLog::query()->sole();
    expect($row->action)->toBe('security.access_denied')
        ->and($row->actor_user_id)->toBe($cashier->id)
        ->and($row->new_values)->toMatchArray(['route' => 't.gate']);
});

it('does not put the query string in the audit row', function (): void {
    loginAs(RoleCode::Cashier);

    $this->get('/_t/admin?token=abc123secret')->assertForbidden();

    expect(json_encode(AuditLog::query()->sole()->new_values))->not->toContain('abc123secret');
});
