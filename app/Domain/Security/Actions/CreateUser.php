<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Data\CreatedUser;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\TemporaryPassword;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Creates a staff account with a temporary password (FR-SEC-004, FR-SEC-012).
 * The password is returned once for the Owner to hand over; only its argon2id
 * hash is stored, and the new user must change it at first login.
 */
final class CreateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
    ) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws ValidationException
     */
    public function handle(User $actor, string $username, string $fullName, array $roleIds): CreatedUser
    {
        Gate::forUser($actor)->authorize('user.manage');

        $username = trim($username);
        $fullName = trim($fullName);

        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            throw ValidationException::withMessages(['username' => 'Use 3 to 64 letters, numbers, dots, dashes or underscores.']);
        }
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            throw ValidationException::withMessages(['full_name' => 'Enter the full name (2 to 150 characters).']);
        }

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            throw ValidationException::withMessages(['role_ids' => 'Choose at least one role.']);
        }

        $temporaryPassword = TemporaryPassword::generate();

        try {
            $user = DB::transaction(function () use ($actor, $username, $fullName, $roleIds, $temporaryPassword): User {
                $roles = Role::query()->whereIn('id', $roleIds)->orderBy('id')->get();

                if ($roles->count() !== count($roleIds)) {
                    throw ValidationException::withMessages(['role_ids' => 'One of the chosen roles does not exist.']);
                }

                $this->rules->assertCanGrantRoles($actor, $roles);

                $user = new User(['username' => $username, 'full_name' => $fullName]);
                $user->forceFill([
                    'password_hash' => Hash::make($temporaryPassword),
                    'is_active' => true,
                    'must_change_password' => true,
                ])->save();

                $user->roles()->attach($roles->mapWithKeys(fn (Role $r): array => [$r->id => ['assigned_by' => $actor->id]])->all());

                $this->audit->record(AuditAction::UserCreated, $actor, 'user', $user->id, newValues: [
                    'username' => $user->username,
                    'full_name' => $user->full_name,
                    'roles' => $roles->pluck('code')->all(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // The unique index on lower(username) is the real guard against two admins racing.
            throw ValidationException::withMessages(['username' => 'That username is already taken.']);
        }

        return new CreatedUser($user, $temporaryPassword);
    }
}
