<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Security\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel merges its own default connections (sqlite, mysql, mariadb,
        // sqlsrv) into config('database.connections') even when config/database.php
        // lists only pgsql. BCIS is PostgreSQL only (PRD section 3, NFR-10), so
        // drop everything else. tests/Feature/ConfigTest.php asserts this.
        $pgsql = config('database.connections.pgsql');

        config(['database.connections' => ['pgsql' => $pgsql]]);
    }

    public function boot(): void
    {
        // One Gate per permission code (INV-17). Policies and `can:` middleware
        // ask these; nothing in the code base checks a role name. Gates call
        // the callback only for a logged-in user, so guests are always refused.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission->value));
        }
    }
}
