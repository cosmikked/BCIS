<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\PasswordAttemptGuard;
use App\Domain\Security\SessionLockState;
use App\Models\User;

/**
 * Unlocks a locked session after the password is typed again (FR-SEC-003).
 * Wrong passwords count toward the same lockout as sign-in. The page itself is
 * never reloaded, so unsaved work stays in place (FR-UI-008).
 */
final class UnlockSession
{
    public function __construct(
        private readonly PasswordAttemptGuard $guard,
        private readonly SessionLockState $state,
    ) {}

    /** @throws LoginFailed */
    public function handle(User $user, string $password, string $ip): void
    {
        // The identity is the signed-in user, never a username from the request.
        $this->guard->attempt($user->username, $password, $ip, AuditAction::SessionUnlocked, AuditAction::UnlockFailed);

        $this->state->unlock();
    }
}
