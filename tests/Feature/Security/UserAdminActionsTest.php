<?php

declare(strict_types=1);

use App\Domain\Security\Actions\AttemptLogin;
use App\Domain\Security\Actions\CreateUser;
use App\Domain\Security\Actions\DeactivateUser;
use App\Domain\Security\Actions\ReactivateUser;
use App\Domain\Security\Actions\ResetUserPassword;
use App\Domain\Security\Actions\UnlockUser;
use App\Domain\Security\Actions\UpdateUserRoles;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\Users;

// FR-SEC-002, FR-SEC-004, FR-SEC-009, FR-SEC-010, FR-SEC-012, INV-17, INV-19, AT-10

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function roleId(RoleCode $code): int
{
    return Role::query()->where('code', $code->value)->firstOrFail()->id;
}

/** A user who may manage users but holds nothing else: used to prove nobody grants beyond their own power. */
function limitedManager(string $username = 'limited'): User
{
    $role = Role::query()->firstOrCreate(['code' => 'USER_ADMIN_ONLY'], ['name' => 'User admin only']);
    $role->permissions()->syncWithoutDetaching([Permission::query()->where('code', 'user.manage')->value('id')]);

    $user = Users::make($username);
    $user->roles()->attach($role->id);

    return $user;
}

function expectRefused(Closure $call, string $part): void
{
    try {
        $call();
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($part);

        return;
    }

    throw new RuntimeException('Expected the action to be refused.');
}

// ---------------------------------------------------------------- create

it('creates a user with a one-time password that is stored only as an argon2id hash', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);

    $created = app(CreateUser::class)->handle($owner, 'new.cashier', 'New Cashier', [roleId(RoleCode::Cashier)]);

    $user = $created->user->fresh();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->password_hash)->toStartWith('$argon2id$')
        ->and(Hash::check($created->temporaryPassword, $user->password_hash))->toBeTrue()
        ->and($created->temporaryPassword)->toHaveLength(16)
        ->and($user->hasPermission('payment.create'))->toBeTrue()
        ->and($user->roles()->first()->pivot->assigned_by)->toBe($owner->id);

    $audit = AuditLog::query()->where('action', 'user.created')->sole();
    expect($audit->actor_user_id)->toBe($owner->id)
        ->and($audit->new_values)->toMatchArray(['username' => 'new.cashier', 'roles' => ['CASHIER']])
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($created->temporaryPassword);
});

it('refuses a duplicate username in any letter case', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    Users::make('Maria');

    expectRefused(fn () => app(CreateUser::class)->handle($owner, 'maria', 'Another Maria', [roleId(RoleCode::Cashier)]), 'already taken');
});

it('validates username, name and roles', function (string $username, string $name, array $roles, string $message): void {
    $owner = Users::make('owner', RoleCode::Owner);

    expectRefused(fn () => app(CreateUser::class)->handle($owner, $username, $name, $roles), $message);
})->with([
    'bad characters' => ['no spaces!', 'Name Here', [1], 'letters, numbers'],
    'too short' => ['ab', 'Name Here', [1], 'letters, numbers'],
    'empty name' => ['good.name', ' ', [1], 'full name'],
    'no role' => ['good.name', 'Name Here', [], 'at least one role'],
    'missing role' => ['good.name', 'Name Here', [999999], 'does not exist'],
]);

it('does not let a user grant roles that hold permissions they do not hold', function (): void {
    $limited = limitedManager();

    foreach ([RoleCode::Owner, RoleCode::Administrator, RoleCode::Cashier] as $code) {
        expectRefused(fn () => app(CreateUser::class)->handle($limited, 'x.'.strtolower($code->value), 'Some Person', [roleId($code)]), 'cannot grant permissions you do not hold');
    }

    expect(User::query()->where('username', 'like', 'x.%')->count())->toBe(0);
});

// ---------------------------------------------------------------- roles

it('changes roles, keeps who assigned them, and audits old and new roles with the reason', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);

    app(UpdateUserRoles::class)->handle($owner, $target->id, [roleId(RoleCode::Cashier), roleId(RoleCode::Auditor)], 'Covers audit reviews');

    $target->refresh();
    expect($target->roles()->pluck('code')->sort()->values()->all())->toBe(['AUDITOR', 'CASHIER'])
        ->and($target->roles()->where('code', 'AUDITOR')->first()->pivot->assigned_by)->toBe($owner->id);

    $audit = AuditLog::query()->where('action', 'user.roles_changed')->sole();
    expect($audit->reason)->toBe('Covers audit reviews')
        ->and($audit->old_values)->toBe(['roles' => ['CASHIER']])
        // Listed in role-id order: Cashier is seeded before Auditor.
        ->and($audit->new_values['roles'])->toBe(['CASHIER', 'AUDITOR']);
});

it('refuses role changes without a reason or with no roles', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);

    expectRefused(fn () => app(UpdateUserRoles::class)->handle($owner, $target->id, [roleId(RoleCode::Auditor)], '  '), 'reason');
    expectRefused(fn () => app(UpdateUserRoles::class)->handle($owner, $target->id, [], 'Valid reason'), 'at least one role');
    expect($target->roles()->pluck('code')->all())->toBe(['CASHIER']);
});

it('stops a limited manager adding the Owner role, even to themselves (privilege escalation)', function (): void {
    $limited = limitedManager();
    // A peer who holds exactly what the limited manager holds, so managing them is allowed.
    $peer = limitedManager('peer');
    $userAdmin = Role::query()->where('code', 'USER_ADMIN_ONLY')->firstOrFail()->id;

    expectRefused(fn () => app(UpdateUserRoles::class)->handle($limited, $limited->id, [$userAdmin, roleId(RoleCode::Owner)], 'Giving myself power'), 'cannot grant permissions you do not hold');
    expectRefused(fn () => app(UpdateUserRoles::class)->handle($limited, $peer->id, [$userAdmin, roleId(RoleCode::Administrator)], 'Promotion'), 'cannot grant permissions you do not hold');

    expect($limited->roles()->pluck('code')->all())->toBe(['USER_ADMIN_ONLY'])
        ->and($peer->roles()->pluck('code')->all())->toBe(['USER_ADMIN_ONLY']);
});

it('stops a limited manager managing an account that holds more than they do', function (): void {
    $limited = limitedManager();
    $owner = Users::make('boss', RoleCode::Owner);

    foreach ([
        fn () => app(ResetUserPassword::class)->handle($limited, $owner->id, 'Taking over the account'),
        fn () => app(DeactivateUser::class)->handle($limited, $owner->id, 'Removing the boss'),
        fn () => app(UnlockUser::class)->handle($limited, $owner->id, 'Unlocking the boss'),
        fn () => app(UpdateUserRoles::class)->handle($limited, $owner->id, [roleId(RoleCode::Cashier)], 'Demoting the boss'),
    ] as $attempt) {
        expectRefused($attempt, 'more permissions than you do');
    }

    $owner->refresh();
    expect($owner->is_active)->toBeTrue()
        ->and($owner->roles()->pluck('code')->all())->toBe(['OWNER'])
        ->and(Hash::check(Users::PASSWORD, $owner->password_hash))->toBeTrue();
});

// ---------------------------------------------------------------- last owner

/** Holds every permission through a role that is NOT the Owner role, so it can act on Owners. */
function superManager(): User
{
    $role = Role::query()->create(['code' => 'ALL_PERMISSIONS', 'name' => 'All permissions']);
    $role->permissions()->attach(Permission::query()->pluck('id')->all());

    $user = Users::make('super');
    $user->roles()->attach($role->id);

    return $user;
}

it('lets one of two Owners step down, then protects the remaining one from losing the role', function (): void {
    $first = Users::make('first', RoleCode::Owner);
    $second = Users::make('second', RoleCode::Owner);

    app(UpdateUserRoles::class)->handle($first, $second->id, [roleId(RoleCode::Administrator)], 'Moved to operations');
    expect($second->fresh()->roles()->pluck('code')->all())->toBe(['ADMINISTRATOR']);

    expectRefused(fn () => app(UpdateUserRoles::class)->handle($first, $first->id, [roleId(RoleCode::Cashier)], 'Stepping down'), 'last active Owner');
    expect($first->fresh()->roles()->pluck('code')->all())->toBe(['OWNER']);
});

it('refuses to deactivate the last active Owner and writes no audit row for it', function (): void {
    $super = superManager();
    $sole = Users::make('sole', RoleCode::Owner);

    expectRefused(fn () => app(DeactivateUser::class)->handle($super, $sole->id, 'Cleaning up'), 'last active Owner');

    expect($sole->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.deactivated')->count())->toBe(0);

    // With a second active Owner the same action is allowed.
    $other = Users::make('other', RoleCode::Owner);
    app(DeactivateUser::class)->handle($super, $sole->id, 'Cleaning up');
    expect($sole->fresh()->is_active)->toBeFalse();

    // ...and now `other` is the last one.
    expectRefused(fn () => app(DeactivateUser::class)->handle($super, $other->id, 'Cleaning up more'), 'last active Owner');
});

it('does not count an inactive Owner when deciding who is last', function (): void {
    $super = superManager();
    $active = Users::make('active.owner', RoleCode::Owner);
    Users::make('gone.owner', RoleCode::Owner, active: false);

    expectRefused(fn () => app(DeactivateUser::class)->handle($super, $active->id, 'Cleaning up'), 'last active Owner');
});

// ---------------------------------------------------------------- deactivate / reactivate

it('deactivates without deleting, ends sessions, keeps history and refuses self-deactivation', function (): void {
    config(['session.driver' => 'database']);
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);
    DB::table('sessions')->insert(['id' => 'maria-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
    app(DeactivateUser::class)->handle($owner, $target->id, 'Resigned');

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(User::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'user.deactivated')->sole();
    expect($audit->reason)->toBe('Resigned')->and($audit->entity_id)->toBe($target->id);

    expectRefused(fn () => app(DeactivateUser::class)->handle($owner, $owner->id, 'Oops'), 'own account');
    expectRefused(fn () => app(DeactivateUser::class)->handle($owner, $target->id, 'Again'), 'already inactive');
});

it('reactivates an account and clears its lockout', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier, active: false);
    $target->forceFill(['failed_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save();

    app(ReactivateUser::class)->handle($owner, $target->id, 'Returned from leave');

    $target->refresh();
    expect($target->is_active)->toBeTrue()->and($target->failed_attempts)->toBe(0)->and($target->locked_until)->toBeNull();
});

// ---------------------------------------------------------------- reset / unlock

it('resets a password: old one dies, temporary one works once, lockout and sessions clear, no secret is audited', function (): void {
    config(['session.driver' => 'database']);
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);
    $target->forceFill(['failed_attempts' => 5, 'locked_until' => now()->addMinutes(10)])->save();
    DB::table('sessions')->insert(['id' => 'maria-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

    $temporary = app(ResetUserPassword::class)->handle($owner, $target->id, 'Forgot password');

    $target->refresh();
    expect(Hash::check(Users::PASSWORD, $target->password_hash))->toBeFalse()
        ->and(Hash::check($temporary, $target->password_hash))->toBeTrue()
        ->and($target->must_change_password)->toBeTrue()
        ->and($target->failed_attempts)->toBe(0)
        ->and($target->locked_until)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'user.password_reset')->sole();
    expect($audit->reason)->toBe('Forgot password')
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($temporary);

    expectRefused(fn () => app(ResetUserPassword::class)->handle($owner, $owner->id, 'Myself'), 'Change password');
});

it('lets a reset user sign in with the temporary password and be forced to change it', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);
    $temporary = app(ResetUserPassword::class)->handle($owner, $target->id, 'Forgot password');

    $user = app(AttemptLogin::class)->handle('maria', $temporary, '127.0.0.1');

    expect($user->id)->toBe($target->id)->and($user->must_change_password)->toBeTrue();
});

it('unlocks a locked-out account so the right password works again (FR-SEC-002)', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $target = Users::make('maria', RoleCode::Cashier);

    foreach (range(1, 5) as $i) {
        try {
            app(AttemptLogin::class)->handle('maria', 'wrong-guess-'.$i, '127.0.0.1');
        } catch (LoginFailed) {
        }
    }
    expect(fn () => app(AttemptLogin::class)->handle('maria', Users::PASSWORD, '127.0.0.1'))
        ->toThrow(LoginFailed::class);

    app(UnlockUser::class)->handle($owner, $target->id, 'Caller verified by phone');

    expect(app(AttemptLogin::class)->handle('maria', Users::PASSWORD, '127.0.0.1')->id)->toBe($target->id)
        ->and(AuditLog::query()->where('action', 'user.unlocked')->sole()->reason)->toBe('Caller verified by phone');
});

// ---------------------------------------------------------------- AT-10

it('refuses every user-management action for a Cashier and changes nothing (AT-10)', function (): void {
    $cashier = Users::make('cashier', RoleCode::Cashier);
    $target = Users::make('maria', RoleCode::Viewer);
    $before = [User::query()->count(), AuditLog::query()->count(), $target->fresh()->password_hash];

    foreach ([
        fn () => app(CreateUser::class)->handle($cashier, 'sneaky', 'Sneaky User', [roleId(RoleCode::Viewer)]),
        fn () => app(UpdateUserRoles::class)->handle($cashier, $target->id, [roleId(RoleCode::Cashier)], 'Because'),
        fn () => app(DeactivateUser::class)->handle($cashier, $target->id, 'Because'),
        fn () => app(ReactivateUser::class)->handle($cashier, $target->id, 'Because'),
        fn () => app(ResetUserPassword::class)->handle($cashier, $target->id, 'Because'),
        fn () => app(UnlockUser::class)->handle($cashier, $target->id, 'Because'),
    ] as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }

    expect([User::query()->count(), AuditLog::query()->count(), $target->fresh()->password_hash])->toBe($before)
        ->and($target->fresh()->roles()->pluck('code')->all())->toBe(['VIEWER'])
        ->and($target->fresh()->is_active)->toBeTrue();
});
