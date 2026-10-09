<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Exceptions\LoginFailed;
use App\Domain\Security\PasswordAttemptGuard;
use App\Domain\Security\UserSessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A user changes their own password, including the forced change after a
 * temporary password (FR-SEC-004). All checks run here, not in the page.
 *
 * The current password goes through PasswordAttemptGuard, so guessing it from
 * an unattended signed-in screen counts toward the lockout. The new password is
 * hashed with argon2id (INV-24) and never audited or logged.
 */
final class ChangeOwnPassword
{
    public function __construct(
        private readonly PasswordAttemptGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(User $user, string $current, string $new, string $confirmation, string $ip, ?string $keepSessionId = null): void
    {
        $min = (int) config('bcis.password_min_length');

        if (mb_strlen($new) < $min) {
            throw ValidationException::withMessages(['new_password' => "The new password must be at least {$min} characters."]);
        }
        if (mb_strlen($new) > 128) {
            throw ValidationException::withMessages(['new_password' => 'The new password must be 128 characters or fewer.']);
        }
        if ($new !== $confirmation) {
            throw ValidationException::withMessages(['new_password' => 'The new password and its confirmation do not match.']);
        }
        if (mb_stripos($new, $user->username) !== false) {
            throw ValidationException::withMessages(['new_password' => 'The new password must not contain your username.']);
        }
        if ($new === $current) {
            throw ValidationException::withMessages(['new_password' => 'The new password must be different from the current one.']);
        }

        try {
            $this->guard->attempt($user->username, $current, $ip, null, AuditAction::PasswordChangeFailed);
        } catch (LoginFailed $e) {
            throw ValidationException::withMessages([
                'current_password' => $e->retryAfterSeconds > 0 ? $e->getMessage() : 'The current password is incorrect.',
            ]);
        }

        DB::transaction(function () use ($user, $new, $ip): void {
            $row = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wasTemporary = $row->must_change_password;

            $row->forceFill([
                'password_hash' => Hash::make($new),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::PasswordChanged, $row, 'user', $row->id, newValues: [
                'forced_change' => $wasTemporary,
                'ip' => $ip,
            ]);
        });

        // Other devices signed in with the old password are signed out.
        UserSessions::revokeAll($user->id, $keepSessionId);
    }
}
