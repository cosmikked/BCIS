<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Contracts\Session\Session;

/**
 * Lock and activity state of the signed-in session (FR-SEC-003, FR-UI-008).
 * It lives in the SERVER session, never in the browser, so a user cannot
 * unlock by editing the page. The browser only shows what the server decided.
 */
final class SessionLockState
{
    private const LOCKED = 'security.locked';

    private const LAST_ACTIVITY = 'security.last_activity';

    public function __construct(
        private readonly Session $session,
        private readonly SettingsRepository $settings,
    ) {}

    public function isLocked(): bool
    {
        return $this->session->get(self::LOCKED) === true;
    }

    public function lock(): void
    {
        $this->session->put(self::LOCKED, true);
    }

    public function unlock(): void
    {
        $this->session->forget(self::LOCKED);
        $this->touch();
    }

    /** Record that the person is active right now. */
    public function touch(): void
    {
        $this->session->put(self::LAST_ACTIVITY, now()->getTimestamp());
    }

    /** Seconds since the last recorded activity; 0 when none is recorded yet. */
    public function idleSeconds(): int
    {
        $last = $this->session->get(self::LAST_ACTIVITY);

        return is_int($last) ? max(0, now()->getTimestamp() - $last) : 0;
    }

    public function lockAfterSeconds(): int
    {
        return max(1, $this->settings->int('security.idle_lock_minutes')) * 60;
    }

    public function secondsUntilLock(): int
    {
        return max(0, $this->lockAfterSeconds() - $this->idleSeconds());
    }

    public function isIdleExpired(): bool
    {
        return $this->idleSeconds() >= $this->lockAfterSeconds();
    }
}
