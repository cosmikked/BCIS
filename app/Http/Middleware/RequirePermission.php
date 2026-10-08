<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\DeniedAccessRecorder;
use App\Domain\Security\Permission;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `permission:payment.create` or `permission:report.view,report.export`
 * (the user must hold ALL listed permissions). Refusals are audited and return
 * 403. This is server-side authorization (INV-17); hiding a menu item is not.
 *
 * Always combine it with `auth`, which handles the login redirect for guests.
 */
final class RequirePermission
{
    public function __construct(private readonly DeniedAccessRecorder $denied) {}

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        if ($codes === []) {
            throw new InvalidArgumentException('The permission middleware needs at least one permission code.');
        }

        foreach ($codes as $code) {
            // A typo in a route file must fail loudly, never silently deny or allow.
            if (Permission::tryFrom($code) === null) {
                throw new InvalidArgumentException("Unknown permission code '{$code}' on route middleware.");
            }
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        foreach ($codes as $code) {
            if (! $user->hasPermission($code)) {
                $this->denied->record($request, $user, array_values($codes));
                abort(403);
            }
        }

        return $next($request);
    }
}
