<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Security\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates the first Owner from environment values (FR-SEC-004). The password is
 * hashed with argon2id and must be changed at first login. An existing Owner
 * account is left untouched, so re-seeding never resets a real password.
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('bcis.seed.owner_username');
        $password = config('bcis.seed.owner_initial_password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Set OWNER_USERNAME and OWNER_INITIAL_PASSWORD in .env before seeding the Owner.');
        }

        DB::transaction(function () use ($username, $password): void {
            $ownerRole = Role::query()->where('code', RoleCode::Owner->value)->firstOrFail();

            $owner = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->first();

            if ($owner === null) {
                $owner = new User(['username' => $username, 'full_name' => (string) config('bcis.seed.owner_full_name')]);
                $owner->forceFill([
                    'password_hash' => Hash::make($password),
                    'is_active' => true,
                    'must_change_password' => true,
                ])->save();
            }

            $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
        });
    }
}
