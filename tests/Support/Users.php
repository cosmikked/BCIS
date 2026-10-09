<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Security\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Test data builder. Passwords here are throwaway values, never real ones. */
final class Users
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public static function make(
        string $username = 'tester',
        ?RoleCode $role = null,
        string $password = self::PASSWORD,
        bool $active = true,
        bool $mustChange = false,
    ): User {
        $user = new User(['username' => $username, 'full_name' => 'Test '.$username]);
        $user->forceFill([
            'password_hash' => Hash::make($password),
            'is_active' => $active,
            'must_change_password' => $mustChange,
        ])->save();

        if ($role !== null) {
            $user->roles()->attach(Role::query()->where('code', $role->value)->firstOrFail()->id);
        }

        return $user;
    }
}
