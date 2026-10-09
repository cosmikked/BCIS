<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Security\Actions\LockSession;
use App\Domain\Security\Actions\UnlockSession;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\SessionLockState;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints behind the lock overlay (FR-SEC-003, FR-UI-008). All three are
 * "any signed-in user" routes: a person may only lock or unlock their own
 * session, and unlock demands their password.
 */
final class SessionLockController extends Controller
{
    public function __construct(private readonly SessionLockState $state) {}

    /** The browser reports whether the person was active; the server answers with the lock state. */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($request->boolean('active') && ! $this->state->isLocked()) {
            $this->state->touch();
        }

        return $this->status();
    }

    public function lock(Request $request, LockSession $lock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $lock->handle($user, 'manual');

        return $this->status();
    }

    public function unlock(Request $request, UnlockSession $unlock): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate(['password' => ['required', 'string', 'max:255']]);

        try {
            $unlock->handle($user, $validated['password'], (string) $request->ip());
        } catch (LoginFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->status();
    }

    private function status(): JsonResponse
    {
        return response()->json([
            'locked' => $this->state->isLocked(),
            'seconds_until_lock' => $this->state->secondsUntilLock(),
        ]);
    }
}
