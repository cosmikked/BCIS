<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\Actions\LockSession;
use App\Domain\Security\Actions\LogoutUser;
use App\Domain\Security\SessionLockState;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request of a signed-in user (FR-SEC-003, FR-SEC-012):
 *  1. a deactivated user is signed out at once, even mid-session;
 *  2. a session idle for longer than the idle limit is locked;
 *  3. while locked, only unlock, heartbeat and logout are served. Ajax and
 *     Livewire calls get 423 and the page shows the lock overlay on top of the
 *     untouched page. A full page load gets a bare lock page with no data.
 *
 * The browser never decides any of this; it only displays the server's answer.
 */
final class EnforceSessionState
{
    /** Routes served while locked. */
    private const WHILE_LOCKED = ['session.heartbeat', 'session.unlock', 'logout'];

    public function __construct(
        private readonly SessionLockState $state,
        private readonly LockSession $lock,
        private readonly LogoutUser $logout,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            $this->logout->handle($user, $request->session(), 'deactivated');

            return $this->expired($request, 'Your account is not active.');
        }

        $isHeartbeat = $request->routeIs('session.heartbeat');

        if (! $this->state->isLocked() && $this->state->isIdleExpired()) {
            $this->lock->handle($user, 'idle');
        }

        if ($this->state->isLocked()) {
            if ($request->routeIs(...self::WHILE_LOCKED)) {
                return $next($request);
            }

            return $this->locked($request);
        }

        // A heartbeat only counts as activity when the browser saw the person
        // move or type; the controller touches the session in that case.
        if (! $isHeartbeat) {
            $this->state->touch();
        }

        return $next($request);
    }

    private function locked(Request $request): Response
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire') || $request->ajax()) {
            return response()->json(['locked' => true, 'message' => 'Session locked.'], 423);
        }

        return response()->view('security.locked', [], 423);
    }

    private function expired(Request $request, string $message): Response
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire') || $request->ajax()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login');
    }
}
