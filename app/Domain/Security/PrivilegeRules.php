<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "A user cannot grant a permission they do not hold" (PRD section 2), and its
 * twin: nobody manages an account that holds more power than they do. Without
 * the twin, a user-manager could reset the Owner's password and sign in as the
 * Owner. All admin Actions call these before changing anything.
 */
final class PrivilegeRules
{
    /**
     * @param  iterable<Role>  $roles
     *
     * @throws ValidationException
     */
    public function assertCanGrantRoles(User $actor, iterable $roles): void
    {
        $codes = [];
        foreach ($roles as $role) {
            foreach ($role->permissions()->pluck('code') as $code) {
                $codes[] = (string) $code;
            }
        }

        $this->assertCanGrantPermissions($actor, $codes);
    }

    /**
     * @param  list<string>  $codes
     *
     * @throws ValidationException
     */
    public function assertCanGrantPermissions(User $actor, array $codes): void
    {
        $missing = array_values(array_diff(array_unique($codes), $this->held($actor)));

        if ($missing !== []) {
            sort($missing);

            throw ValidationException::withMessages([
                'form' => 'You cannot grant permissions you do not hold: '.implode(', ', $missing).'.',
            ]);
        }
    }

    /**
     * The target's current permissions must all be ones the actor holds.
     *
     * @throws ValidationException
     */
    public function assertCanManage(User $actor, User $target): void
    {
        $target->forgetPermissionCache();
        $missing = array_values(array_diff($target->permissionCodes(), $this->held($actor)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'form' => 'You cannot manage an account that holds more permissions than you do.',
            ]);
        }
    }

    /** @return list<string> */
    private function held(User $actor): array
    {
        $actor->forgetPermissionCache();

        return $actor->is_active ? $actor->permissionCodes() : [];
    }
}
