<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Security\Permission as PermissionCode;
use App\Domain\Security\RoleCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds roles, permissions and the PRD section 2 matrix. Safe to run again:
 * it adds what is missing and resets the seeded roles to the matrix.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];
            foreach (PermissionCode::cases() as $code) {
                $permissionIds[$code->value] = Permission::query()
                    ->updateOrCreate(['code' => $code->value], ['description' => $code->description()])
                    ->id;
            }

            foreach (RoleCode::cases() as $roleCode) {
                $role = Role::query()->updateOrCreate(['code' => $roleCode->value], ['name' => $roleCode->label()]);

                $role->permissions()->sync(
                    array_map(fn (PermissionCode $p): int => $permissionIds[$p->value], $roleCode->permissions()),
                );
            }
        });
    }
}
