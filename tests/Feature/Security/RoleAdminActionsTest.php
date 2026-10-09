<?php

declare(strict_types=1);

use App\Domain\Security\Actions\UpdateRolePermissions;
use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Users;

// FR-SEC-010, PRD section 2 (grant rule), AT-10

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function codesOf(RoleCode $code): array
{
    return Role::query()->where('code', $code->value)->firstOrFail()->permissions()->pluck('code')->sort()->values()->all();
}

function idOf(RoleCode $code): int
{
    return Role::query()->where('code', $code->value)->firstOrFail()->id;
}

/** Holds role.manage and payment.create, but little else. */
function roleManager(): User
{
    $role = Role::query()->create(['code' => 'ROLE_ADMIN_ONLY', 'name' => 'Role admin only']);
    $role->permissions()->attach(Permission::query()->whereIn('code', ['role.manage', 'payment.create'])->pluck('id')->all());

    $user = Users::make('rolemgr');
    $user->roles()->attach($role->id);

    return $user;
}

function refusedWith(Closure $call, string $part): void
{
    try {
        $call();
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($part);

        return;
    }

    throw new RuntimeException('Expected the action to be refused.');
}

it('changes a role, audits old and new permissions with the reason, and applies at once', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $viewer = Users::make('viewer', RoleCode::Viewer);
    expect($viewer->hasPermission('report.export'))->toBeFalse();

    $new = array_merge(codesOf(RoleCode::Viewer), ['report.export']);
    app(UpdateRolePermissions::class)->handle($owner, idOf(RoleCode::Viewer), $new, 'Viewers now export reports');

    expect(codesOf(RoleCode::Viewer))->toContain('report.export')
        ->and($viewer->fresh()->hasPermission('report.export'))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'role.permissions_changed')->sole();
    expect($audit->reason)->toBe('Viewers now export reports')
        ->and($audit->new_values['added'])->toBe(['report.export'])
        ->and($audit->new_values['removed'])->toBe([])
        ->and($audit->old_values['permissions'])->not->toContain('report.export');
});

it('can remove permissions as well as add them', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    app(UpdateRolePermissions::class)->handle($owner, idOf(RoleCode::Cashier), ['subscriber.view', 'payment.create'], 'Tighten the cashier role');

    expect(codesOf(RoleCode::Cashier))->toBe(['payment.create', 'subscriber.view']);
    expect(AuditLog::query()->where('action', 'role.permissions_changed')->sole()->new_values['removed'])->toContain('gcash.record');
});

it('never lets a user grant a permission they do not hold', function (): void {
    $manager = roleManager(); // holds role.manage and payment.create only

    refusedWith(
        fn () => app(UpdateRolePermissions::class)->handle($manager, idOf(RoleCode::Viewer), array_merge(codesOf(RoleCode::Viewer), ['user.manage']), 'Sneaky'),
        'cannot grant permissions you do not hold: user.manage',
    );
    refusedWith(
        fn () => app(UpdateRolePermissions::class)->handle($manager, idOf(RoleCode::Viewer), array_merge(codesOf(RoleCode::Viewer), ['payment.reverse']), 'Sneaky'),
        'payment.reverse',
    );

    expect(codesOf(RoleCode::Viewer))->toBe(Role::query()->where('code', 'VIEWER')->firstOrFail()->permissions()->pluck('code')->sort()->values()->all())
        ->and(codesOf(RoleCode::Viewer))->not->toContain('user.manage')
        ->and(AuditLog::query()->where('action', 'role.permissions_changed')->count())->toBe(0);

    // Granting something they DO hold is fine.
    app(UpdateRolePermissions::class)->handle($manager, idOf(RoleCode::Viewer), array_merge(codesOf(RoleCode::Viewer), ['payment.create']), 'Viewers may take payments');
    expect(codesOf(RoleCode::Viewer))->toContain('payment.create');
});

it('keeps the Owner role untouchable', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    refusedWith(fn () => app(UpdateRolePermissions::class)->handle($owner, idOf(RoleCode::Owner), ['dashboard.view'], 'Trimming'), 'cannot be edited');

    expect(codesOf(RoleCode::Owner))->toHaveCount(36);
});

it('rejects unknown permission codes and a missing reason', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    refusedWith(fn () => app(UpdateRolePermissions::class)->handle($owner, idOf(RoleCode::Viewer), ['made.up'], 'Reason'), 'Unknown permission');
    refusedWith(fn () => app(UpdateRolePermissions::class)->handle($owner, idOf(RoleCode::Viewer), ['dashboard.view'], ''), 'reason');
    expect(codesOf(RoleCode::Viewer))->toHaveCount(7);
});

it('refuses a Cashier and changes nothing (AT-10)', function (): void {
    $cashier = Users::make('cashier', RoleCode::Cashier);

    expect(fn () => app(UpdateRolePermissions::class)->handle($cashier, idOf(RoleCode::Cashier), Permission::query()->pluck('code')->all(), 'Giving myself everything'))
        ->toThrow(AuthorizationException::class);

    expect(codesOf(RoleCode::Cashier))->toHaveCount(11)
        ->and(AuditLog::query()->where('action', 'role.permissions_changed')->count())->toBe(0);
});
