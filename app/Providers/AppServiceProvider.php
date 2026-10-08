<?php

declare(strict_types=1);

namespace App\Providers;

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
        //
    }
}
