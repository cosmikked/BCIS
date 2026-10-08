<?php

declare(strict_types=1);

use App\Domain\Security\Permission as PermissionCode;
use App\Domain\Security\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

// PRD section 2 matrix, INV-17, FR-SEC-001, FR-SEC-005, INV-24

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function userWithRole(RoleCode $roleCode, bool $active = true): User
{
    $user = new User(['username' => 'u'.uniqid(), 'full_name' => 'Test User']);
    $user->forceFill(['password_hash' => Hash::make('not-a-real-password'), 'is_active' => $active])->save();
    $user->roles()->attach(Role::query()->where('code', $roleCode->value)->firstOrFail()->id);

    return $user;
}

function permissionsOf(RoleCode $roleCode): array
{
    return Role::query()->where('code', $roleCode->value)->firstOrFail()
        ->permissions()->pluck('code')->sort()->values()->all();
}

it('seeds the 36 permissions from the PRD matrix', function (): void {
    expect(PermissionCode::cases())->toHaveCount(36)
        ->and(Permission::query()->count())->toBe(36)
        ->and(Role::query()->count())->toBe(7);
});

it('grants each role the number of permissions shown in the PRD matrix', function (RoleCode $role, int $expected): void {
    expect(permissionsOf($role))->toHaveCount($expected);
})->with([
    'Owner' => [RoleCode::Owner, 36],
    'Administrator' => [RoleCode::Administrator, 28],
    'Cashier' => [RoleCode::Cashier, 11],
    'Collection Supervisor' => [RoleCode::CollectionSupervisor, 11],
    'Auditor' => [RoleCode::Auditor, 9],
    'Technician' => [RoleCode::Technician, 2],
    'Viewer' => [RoleCode::Viewer, 7],
]);

it('keeps the rules that matter in the matrix', function (): void {
    // Only the Owner approves suspensions and manages users, roles, settings, backups.
    foreach (['service.suspend.approve', 'user.manage', 'role.manage', 'settings.manage', 'backup.create', 'backup.restore'] as $code) {
        $holders = collect(RoleCode::cases())->filter(fn ($r) => in_array($code, permissionsOf($r), true))->all();
        expect($holders)->toBe([RoleCode::Owner]);
    }

    // The Technician sees no money (PRD section 2).
    expect(permissionsOf(RoleCode::Technician))->toBe(['service.execute', 'service.view']);

    // The Viewer cannot change anything.
    expect(collect(permissionsOf(RoleCode::Viewer))->filter(fn ($c) => ! str_ends_with($c, '.view')))->toBeEmpty();
});

it('can be seeded twice without duplicating anything', function (): void {
    $this->seed(RolePermissionSeeder::class);

    expect(Permission::query()->count())->toBe(36)
        ->and(permissionsOf(RoleCode::Cashier))->toHaveCount(11);
});

it('defines a gate for every permission code and follows the user role', function (): void {
    foreach (PermissionCode::cases() as $permission) {
        expect(Gate::has($permission->value))->toBeTrue();
    }

    $cashier = userWithRole(RoleCode::Cashier);

    expect(Gate::forUser($cashier)->allows('payment.create'))->toBeTrue()
        ->and(Gate::forUser($cashier)->allows('user.manage'))->toBeFalse()
        ->and($cashier->hasPermission('payment.create'))->toBeTrue()
        ->and($cashier->hasPermission('user.manage'))->toBeFalse();
});

it('refuses every permission to an inactive user and to a guest', function (): void {
    $inactiveOwner = userWithRole(RoleCode::Owner, active: false);

    expect($inactiveOwner->hasPermission('user.manage'))->toBeFalse()
        ->and(Gate::forUser($inactiveOwner)->allows('user.manage'))->toBeFalse()
        ->and(Gate::allows('user.manage'))->toBeFalse();
});

it('unions the permissions of several roles', function (): void {
    $user = userWithRole(RoleCode::Cashier);
    $user->roles()->attach(Role::query()->where('code', RoleCode::Auditor->value)->firstOrFail()->id);

    expect($user->hasPermission('payment.create'))->toBeTrue()
        ->and($user->hasPermission('audit.view'))->toBeTrue()
        ->and($user->hasPermission('user.manage'))->toBeFalse();
});

it('stores an argon2id hash and never the plaintext (FR-SEC-001, INV-24)', function (): void {
    $user = userWithRole(RoleCode::Cashier);
    $stored = (string) DB::table('users')->where('id', $user->id)->value('password_hash');

    expect($stored)->toStartWith('$argon2id$')->not->toContain('not-a-real-password')
        ->and(Hash::check('not-a-real-password', $stored))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('password_hash');
});

it('treats usernames case-insensitively', function (): void {
    $first = new User(['username' => 'Maria', 'full_name' => 'Maria One']);
    $first->forceFill(['password_hash' => Hash::make('x')])->save();

    $second = new User(['username' => 'maria', 'full_name' => 'Maria Two']);

    expect(fn () => $second->forceFill(['password_hash' => Hash::make('x')])->save())
        ->toThrow(QueryException::class);
});
