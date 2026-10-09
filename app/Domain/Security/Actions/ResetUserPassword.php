<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\PasswordAttemptGuard;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\Reason;
use App\Domain\Security\TemporaryPassword;
use App\Domain\Security\UserSessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Gives a user a new temporary password (FR-SEC-012). The old password stops
 * working at once, every session of that user ends, the lockout clears, and the
 * user must choose their own password at next sign-in. The audit row records
 * that a reset happened, never the password.
 */
final class ResetUserPassword
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
        private readonly PasswordAttemptGuard $guard,
    ) {}

    /**
     * @return string The temporary password. Show it once.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $targetId, string $reason): string
    {
        Gate::forUser($actor)->authorize('user.manage');
        $reason = Reason::require($reason);

        $temporary = TemporaryPassword::generate();

        $target = DB::transaction(function () use ($actor, $targetId, $reason, $temporary): User {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            if ($target->id === $actor->id) {
                throw ValidationException::withMessages(['form' => 'Use "Change password" for your own account.']);
            }

            // Resetting a password is the same as signing in as that person.
            $this->rules->assertCanManage($actor, $target);

            $target->forceFill([
                'password_hash' => Hash::make($temporary),
                'must_change_password' => true,
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->audit->record(AuditAction::UserPasswordReset, $actor, 'user', $target->id, $reason);

            return $target;
        });

        $this->guard->clear($target);
        UserSessions::revokeAll($target->id);

        return $temporary;
    }
}
