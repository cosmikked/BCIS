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
 * One synthetic demo user per non-Owner role. The shared password comes from
 * SEED_DEMO_PASSWORD, never from the repository. Demo accounts are not
 * temporary-password accounts, so they can log in straight away for the demo.
 */
class DemoUserSeeder extends Seeder
{
    /** @var array<string, array{0: RoleCode, 1: string}> username => [role, full name] */
    private const USERS = [
        'demo.admin' => [RoleCode::Administrator, 'Demo Administrator'],
        'demo.cashier' => [RoleCode::Cashier, 'Demo Cashier'],
        'demo.supervisor' => [RoleCode::CollectionSupervisor, 'Demo Collection Supervisor'],
        'demo.auditor' => [RoleCode::Auditor, 'Demo Auditor'],
        'demo.technician' => [RoleCode::Technician, 'Demo Technician'],
        'demo.viewer' => [RoleCode::Viewer, 'Demo Viewer'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $password = config('bcis.seed.demo_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set SEED_DEMO_PASSWORD in .env before seeding demo users.');
        }

        DB::transaction(function () use ($password): void {
            foreach (self::USERS as $username => [$roleCode, $fullName]) {
                $role = Role::query()->where('code', $roleCode->value)->firstOrFail();

                $user = User::query()->where('username', $username)->first();

                if ($user === null) {
                    $user = new User(['username' => $username, 'full_name' => $fullName]);
                    $user->forceFill([
                        'password_hash' => Hash::make($password),
                        'is_active' => true,
                        'must_change_password' => false,
                    ])->save();
                }

                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        });
    }
}
