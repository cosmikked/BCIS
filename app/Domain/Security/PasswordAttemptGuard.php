<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Security\Exceptions\LoginFailed;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one place a typed password is checked: sign-in, lock-screen unlock and the
 * "current password" box all go through here, so they share one failure counter
 * and one lockout (FR-SEC-002).
 *
 * - Generic failure: wrong password, unknown user and inactive user all give the
 *   same message, and unknown users still pay for a hash check (no timing leak).
 * - Lockout: after N failures (default 5) the username is locked for M minutes
 *   (default 15). The lock lives in RateLimiter, so it also works for usernames
 *   that do not exist. For real users it is mirrored in users.failed_attempts
 *   and locked_until, so it survives a cache flush and the Owner can see it.
 * - Every failure, lockout and success writes an audit row. Blocked attempts
 *   made while already locked are not audited, so an attacker cannot flood the
 *   append-only table.
 */
final class PasswordAttemptGuard
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @param  AuditAction|null  $onSuccess  Audit row for a correct password, or null for none.
     *
     * @throws LoginFailed
     */
    public function attempt(string $username, string $password, string $ip, ?AuditAction $onSuccess, AuditAction $onFailure): User
    {
        $username = trim($username);
        $key = sha1(mb_strtolower($username));
        $attemptsKey = "login-attempts:{$key}";
        $lockKey = "login-lock:{$key}";

        $max = max(1, $this->settings->int('security.max_login_attempts'));
        $lockSeconds = max(1, $this->settings->int('security.lockout_minutes')) * 60;

        $user = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->first();

        $remaining = $this->secondsLocked($lockKey, $user);
        if ($remaining > 0) {
            throw LoginFailed::lockedOut($remaining);
        }

        // Always hash-compare, even for an unknown user, so timing is the same.
        $matches = Hash::check($password, $user !== null ? $user->password_hash : $this->dummyHash());

        if ($user !== null && $matches && $user->is_active) {
            DB::transaction(function () use ($user, $onSuccess, $ip): void {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['failed_attempts' => 0, 'locked_until' => null]);

                if ($onSuccess === AuditAction::Login) {
                    $locked->forceFill(['last_login_at' => now()]);
                }

                $locked->save();

                if ($onSuccess !== null) {
                    $this->audit->record($onSuccess, $locked, newValues: ['ip' => $ip]);
                }
            });

            $this->limiter->clear($attemptsKey);

            return $user;
        }

        $reason = match (true) {
            $user === null => 'unknown_user',
            ! $user->is_active => 'inactive_user',
            default => 'bad_password',
        };

        $attempts = $this->limiter->hit($attemptsKey, $lockSeconds);
        $lockedNow = $attempts >= $max;

        DB::transaction(function () use ($user, $onFailure, $ip, $reason, $attempts, $lockedNow, $lockSeconds): void {
            if ($user !== null) {
                $row = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $row->forceFill([
                    'failed_attempts' => min($attempts, 65535),
                    'locked_until' => $lockedNow ? now()->addSeconds($lockSeconds) : $row->locked_until,
                ])->save();
            }

            // The typed username is NOT stored: people sometimes type their
            // password into the username box. A known user is identified by actor.
            $this->audit->record($onFailure, $user, newValues: [
                'ip' => $ip,
                'reason' => $reason,
                'attempts' => $attempts,
            ]);

            if ($lockedNow) {
                $this->audit->record(AuditAction::Lockout, $user, newValues: [
                    'ip' => $ip,
                    'known_user' => $user !== null,
                    'locked_seconds' => $lockSeconds,
                ]);
            }
        });

        if ($lockedNow) {
            $this->limiter->clear($attemptsKey);
            $this->limiter->hit($lockKey, $lockSeconds);

            throw LoginFailed::lockedOut($lockSeconds);
        }

        throw LoginFailed::invalid();
    }

    /** Owner unlock (slice d) and a successful password reset call this. */
    public function clear(User $user): void
    {
        $key = sha1(mb_strtolower($user->username));
        $this->limiter->clear("login-attempts:{$key}");
        $this->limiter->clear("login-lock:{$key}");
    }

    private function secondsLocked(string $lockKey, ?User $user): int
    {
        $fromLimiter = $this->limiter->tooManyAttempts($lockKey, 1) ? $this->limiter->availableIn($lockKey) : 0;
        $fromUser = $user?->locked_until !== null && $user->locked_until->isFuture()
            ? (int) now()->diffInSeconds($user->locked_until, true)
            : 0;

        return max($fromLimiter, $fromUser);
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}
