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

/** Owner unlock of a locked-out account (FR-SEC-002). */
final class UnlockUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
        private readonly PasswordAttemptGuard $guard,
    ) {}

    public function handle(User $actor, int $targetId, string $reason): void
    {
        Gate::forUser($actor)->authorize('user.manage');
        $reason = Reason::require($reason);

        $target = DB::transaction(function () use ($actor, $targetId, $reason): User {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            $this->rules->assertCanManage($actor, $target);

            $target->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

            $this->audit->record(AuditAction::UserUnlocked, $actor, 'user', $target->id, $reason);

            return $target;
        });

        $this->guard->clear($target);
    }
}
