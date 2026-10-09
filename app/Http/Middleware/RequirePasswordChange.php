<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user with a temporary password can only change it (FR-SEC-004). Every other
 * request, page or action, is sent to the change-password screen first.
 */
final class RequirePasswordChange
{
    /** Routes a user with a temporary password may still reach. */
    private const ALLOWED = ['password.change', 'logout', 'session.heartbeat', 'session.lock', 'session.unlock'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        // Livewire updates run on /livewire/update, which has no route name of
        // ours. Let the change-password page's own component calls through.
        if ($request->hasHeader('X-Livewire')) {
            if ($this->isChangePasswordComponent($request)) {
                return $next($request);
            }

            return response()->json(['message' => 'You must change your password first.'], 403);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'You must change your password first.'], 403);
        }

        return redirect()->route('password.change');
    }

    private function isChangePasswordComponent(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $name = is_array($decoded) ? ($decoded['memo']['name'] ?? null) : null;

            if ($name !== 'security.change-password') {
                return false;
            }
        }

        return true;
    }
}
