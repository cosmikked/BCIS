<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route;

/**
 * TO-08 / FR-SEC-005: every route must either be on the explicit public list,
 * be an "any logged-in user" route on the explicit authenticated list, or sit
 * behind `auth` plus a permission check (`permission:` or `can:`).
 */
final class RouteInventory
{
    /**
     * @param  iterable<Route>  $routes
     * @param  list<string>  $public  Route names reachable without logging in.
     * @param  list<string>  $authenticatedOnly  Route names any logged-in user may reach.
     * @return list<string> Human-readable violations; empty when the inventory is clean.
     */
    public static function violations(iterable $routes, array $public, array $authenticatedOnly): array
    {
        $violations = [];
        $seen = [];

        foreach ($routes as $route) {
            $uri = $route->uri();

            // Framework endpoints: Livewire's update and asset routes (every
            // component action authorizes itself, see LivewireActionInventory)
            // and the local storage disk route.
            if (str_starts_with($uri, 'livewire') || str_starts_with($uri, 'storage')) {
                continue;
            }

            $name = $route->getName();

            if ($name === null) {
                $violations[] = "Route '{$uri}' has no name, so it cannot be audited.";

                continue;
            }

            $seen[] = $name;
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));

            if (in_array($name, $public, true)) {
                continue;
            }

            $hasAuth = self::any($middleware, fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:'));
            $hasPermission = self::any($middleware, fn (string $m): bool => str_starts_with($m, 'permission:') || str_starts_with($m, 'can:'));

            if ($hasAuth && ($hasPermission || in_array($name, $authenticatedOnly, true))) {
                continue;
            }

            $violations[] = "Route '{$name}' ({$uri}) has no permission check and is not on a public or authenticated-only list.";
        }

        foreach (array_merge($public, $authenticatedOnly) as $listed) {
            if (! in_array($listed, $seen, true)) {
                $violations[] = "Listed route '{$listed}' does not exist. Remove it from config/bcis.php.";
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $items
     * @param  callable(string): bool  $test
     */
    private static function any(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }
}
