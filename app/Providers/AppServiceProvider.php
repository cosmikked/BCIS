<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Security\Permission;
use App\Domain\Security\SettingsRepository;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Domain\Subscribers\Search\Sources\AccountNumberSource;
use App\Domain\Subscribers\Search\Sources\AddressSource;
use App\Domain\Subscribers\Search\Sources\ContactSource;
use App\Domain\Subscribers\Search\Sources\NameSource;
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

        // One settings read per key per request (FR-SEC-013).
        $this->app->scoped(SettingsRepository::class);

        // Global search (FR-SUB-003). Each phase that adds a searchable thing
        // (invoice number, receipt number, GCash reference) adds one
        // SearchSource class to this list; GlobalSearch itself does not change.
        $this->app->tag([
            AccountNumberSource::class,
            NameSource::class,
            ContactSource::class,
            AddressSource::class,
        ], 'subscriber.search.sources');
        $this->app->when(GlobalSearch::class)->needs('$sources')->giveTagged('subscriber.search.sources');
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
