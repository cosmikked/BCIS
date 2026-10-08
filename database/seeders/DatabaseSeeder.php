<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data (PRD section 18) is synthetic only. Later phases add their
     * seeders to this list.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            OwnerSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
