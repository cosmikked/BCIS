<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\PasswordAttemptGuard;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\Reason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Turns a deactivated account back on, with a lockout-free start. */
final class ReactivateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
        private readonly PasswordAttemptGuard $guard,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $targetId, string $reason): void
    {
        Gate::forUser($actor)->authorize('user.manage');
        $reason = Reason::require($reason);

        $target = DB::transaction(function () use ($actor, $targetId, $reason): User {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            if ($target->is_active) {
                throw ValidationException::withMessages(['form' => 'This account is already active.']);
            }

            $this->rules->assertCanManage($actor, $target);

            $target->forceFill(['is_active' => true, 'failed_attempts' => 0, 'locked_until' => null])->save();

            $this->audit->record(AuditAction::UserReactivated, $actor, 'user', $target->id, $reason,
                oldValues: ['is_active' => false],
                newValues: ['is_active' => true],
            );

            return $target;
        });

        $this->guard->clear($target);
    }
}
