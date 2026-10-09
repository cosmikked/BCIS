<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Users;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Users as TestUsers;

// AT-10, FR-SEC-005, FR-SEC-006, FR-SEC-007, FR-SEC-012, INV-17

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

function adminRole(RoleCode $code): int
{
    return Role::query()->where('code', $code->value)->firstOrFail()->id;
}

// ------------------------------------------------------------------ routes

it('serves the admin screens to an Owner', function (): void {
    $this->actingAs(TestUsers::make('owner', RoleCode::Owner));

    $this->get(route('admin.users'))->assertOk()->assertSee('New user');
    $this->get(route('admin.roles'))->assertOk()->assertSee('Permission matrix');
});

it('refuses every Administration route to every role that lacks the permission (AT-10)', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->map(fn ($route) => $route->getName())
        ->values();

    expect($routes->all())->not->toBe([]);

    foreach ([RoleCode::Cashier, RoleCode::Administrator, RoleCode::Auditor, RoleCode::Technician, RoleCode::Viewer, RoleCode::CollectionSupervisor] as $role) {
        $user = TestUsers::make('u.'.strtolower($role->value), $role);

        foreach ($routes as $name) {
            $this->actingAs($user)->get(route($name))->assertForbidden();
        }
    }

    // Each refusal was audited.
    expect(AuditLog::query()->where('action', 'security.access_denied')->count())->toBe($routes->count() * 6);
});

it('sends a guest to sign in instead of the admin screens', function (): void {
    $this->get(route('admin.users'))->assertRedirect(route('login'));
});

// ------------------------------------------------------------------ Livewire: Cashier refused

it('refuses a Cashier on every admin component without running anything', function (): void {
    $cashier = TestUsers::make('cashier', RoleCode::Cashier);

    Livewire::actingAs($cashier)->test(Users::class)->assertForbidden();
    Livewire::actingAs($cashier)->test(Roles::class)->assertForbidden();
});

it('refuses every admin component method when permission is lost after the page was open (AT-10)', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $victim = TestUsers::make('victim', RoleCode::Viewer);
    $before = $victim->fresh()->password_hash;

    $cashier = TestUsers::make('cashier', RoleCode::Cashier);

    // The Owner's page is open in the browser, then the signed-in user is someone
    // without the permission: the same as a Cashier calling the methods directly.
    // A component is unusable after a refusal, so each method gets a fresh page.
    foreach (['select' => [$victim->id], 'create' => [], 'saveRoles' => [], 'deactivate' => [], 'reactivate' => [], 'resetPassword' => [], 'unlock' => [], 'dismissPassword' => []] as $method => $args) {
        $page = Livewire::actingAs($owner)->test(Users::class)->call('select', $victim->id);
        Auth::setUser($cashier);

        $page->call($method, ...$args)->assertForbidden();
    }
    foreach (['select' => [adminRole(RoleCode::Viewer)], 'save' => []] as $method => $args) {
        $page = Livewire::actingAs($owner)->test(Roles::class)->call('select', adminRole(RoleCode::Viewer));
        Auth::setUser($cashier);

        $page->call($method, ...$args)->assertForbidden();
    }

    $victim->refresh();
    expect($victim->is_active)->toBeTrue()
        ->and($victim->password_hash)->toBe($before)
        ->and($victim->roles()->pluck('code')->all())->toBe(['VIEWER'])
        ->and(Role::query()->where('code', 'VIEWER')->firstOrFail()->permissions()->count())->toBe(7)
        ->and(AuditLog::query()->where('action', 'like', 'user.%')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'role.permissions_changed')->count())->toBe(0);
});

// ------------------------------------------------------------------ Livewire: tampering

it('rejects tampering with the locked selected-user id (FR-SEC-007)', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $a = TestUsers::make('alice', RoleCode::Viewer);
    $b = TestUsers::make('bob', RoleCode::Viewer);

    $component = Livewire::actingAs($owner)->test(Users::class)->call('select', $a->id);

    expect(fn () => $component->set('selectedId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $component->set('reason', 'Resigned')->call('deactivate');

    expect($a->fresh()->is_active)->toBeFalse()
        ->and($b->fresh()->is_active)->toBeTrue();
});

it('rejects tampering with the locked one-time password display and status', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $component = Livewire::actingAs($owner)->test(Users::class);

    expect(fn () => $component->set('revealedPassword', 'forged-value'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('status', 'forged'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rejects tampering with the selected role id on the roles screen', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $component = Livewire::actingAs($owner)->test(Roles::class)->call('select', adminRole(RoleCode::Viewer));

    expect(fn () => $component->set('selectedRoleId', adminRole(RoleCode::Owner)))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('re-validates tampered role ids in the Action: a limited manager cannot smuggle in the Owner role', function (): void {
    $role = Role::query()->create(['code' => 'USER_ADMIN_ONLY', 'name' => 'User admin only']);
    $role->permissions()->attach(Permission::query()->where('code', 'user.manage')->value('id'));
    $limited = TestUsers::make('limited');
    $limited->roles()->attach($role->id);
    $target = TestUsers::make('maria', RoleCode::Cashier);

    // Opening the screen and ticking boxes is allowed; the Owner checkbox is tampered in.
    Livewire::actingAs($limited)->test(Users::class)
        ->call('select', $target->id)
        ->set('roleIds', [adminRole(RoleCode::Cashier), adminRole(RoleCode::Owner)])
        ->set('reason', 'Promotion')
        ->call('saveRoles')
        ->assertHasErrors('form');

    expect($target->fresh()->roles()->pluck('code')->all())->toBe(['CASHIER']);

    Livewire::actingAs($limited)->test(Users::class)
        ->set('newUsername', 'sneaky.owner')
        ->set('newFullName', 'Sneaky Owner')
        ->set('newRoleIds', [adminRole(RoleCode::Owner)])
        ->call('create')
        ->assertHasErrors('form');

    expect(User::query()->where('username', 'sneaky.owner')->exists())->toBeFalse();
});

it('re-validates tampered permission codes on the roles screen', function (): void {
    $role = Role::query()->create(['code' => 'ROLE_ADMIN_ONLY', 'name' => 'Role admin only']);
    $role->permissions()->attach(Permission::query()->where('code', 'role.manage')->value('id'));
    $limited = TestUsers::make('rolemgr');
    $limited->roles()->attach($role->id);

    Livewire::actingAs($limited)->test(Roles::class)
        ->call('select', adminRole(RoleCode::Viewer))
        ->set('codes', ['dashboard.view', 'backup.restore'])
        ->set('reason', 'Tamper test')
        ->call('save')
        ->assertHasErrors('form');

    expect(Role::query()->where('code', 'VIEWER')->firstOrFail()->permissions()->pluck('code')->all())->not->toContain('backup.restore');
});

it('refuses a user id that does not exist instead of acting on something else', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);

    Livewire::actingAs($owner)->test(Users::class)->call('select', 999999)->assertNotFound();
});

// ------------------------------------------------------------------ Livewire: happy paths

it('creates a user, shows the one-time password once, and never audits it', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);

    $component = Livewire::actingAs($owner)->test(Users::class)
        ->set('newUsername', 'new.hire')
        ->set('newFullName', 'New Hire')
        ->set('newRoleIds', [adminRole(RoleCode::Cashier)])
        ->call('create')
        ->assertHasNoErrors();

    $password = $component->get('revealedPassword');
    expect($password)->toBeString()->toHaveLength(16);
    $component->assertSee($password)->assertSee('shown only once');

    $user = User::query()->where('username', 'new.hire')->firstOrFail();
    expect(Hash::check($password, $user->password_hash))->toBeTrue()
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($password);

    $component->call('dismissPassword')->assertDontSee($password);
    expect($component->get('revealedPassword'))->toBeNull();
});

it('resets a password from the screen and shows the temporary one once', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $target = TestUsers::make('maria', RoleCode::Cashier);

    $component = Livewire::actingAs($owner)->test(Users::class)
        ->call('select', $target->id)
        ->set('reason', 'Forgot password')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $temporary = $component->get('revealedPassword');
    expect(Hash::check($temporary, $target->fresh()->password_hash))->toBeTrue()
        ->and($target->fresh()->must_change_password)->toBeTrue();

    // Selecting another user hides it again.
    $component->call('select', $owner->id)->assertDontSee($temporary);
});

it('requires a reason for every change made on the screen', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $target = TestUsers::make('maria', RoleCode::Cashier);

    Livewire::actingAs($owner)->test(Users::class)
        ->call('select', $target->id)
        ->call('deactivate')
        ->assertHasErrors('reason');

    expect($target->fresh()->is_active)->toBeTrue();
});

it('shows a deactivated user still by name in the list and the audit history', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $target = TestUsers::make('maria', RoleCode::Cashier);

    $component = Livewire::actingAs($owner)->test(Users::class)
        ->call('select', $target->id)
        ->set('reason', 'Resigned')
        ->call('deactivate');

    $component->assertSee('maria')->assertSee('Inactive');
    expect(AuditLog::query()->where('action', 'user.deactivated')->sole()->entity_id)->toBe($target->id);
});

it('shows the full permission matrix', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);

    Livewire::actingAs($owner)->test(Roles::class)
        ->assertSee('payment.allocate_manual')
        ->assertSee('Collection Supervisor')
        ->assertSee('Read-only Viewer');
});

it('edits a role from the screen with a reason', function (): void {
    $owner = TestUsers::make('owner', RoleCode::Owner);
    $viewerCodes = Role::query()->where('code', 'VIEWER')->firstOrFail()->permissions()->pluck('code')->all();

    Livewire::actingAs($owner)->test(Roles::class)
        ->call('select', adminRole(RoleCode::Viewer))
        ->set('codes', array_merge($viewerCodes, ['report.export']))
        ->set('reason', 'Viewers export reports')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Permissions updated.');

    expect(Role::query()->where('code', 'VIEWER')->firstOrFail()->permissions()->pluck('code')->all())->toContain('report.export');
});
