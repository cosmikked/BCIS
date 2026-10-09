<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\LastOwnerGuard;
use App\Domain\Security\PrivilegeRules;
use App\Domain\Security\Reason;
use App\Domain\Security\UserSessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Switches an account off. Users are never deleted (INV-19, FR-SEC-012), so their
 * name stays on every audit row and receipt they touched. The last active Owner
 * cannot be deactivated.
 */
final class DeactivateUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PrivilegeRules $rules,
        private readonly LastOwnerGuard $lastOwner,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $targetId, string $reason): void
    {
        Gate::forUser($actor)->authorize('user.manage');
        $reason = Reason::require($reason);

        DB::transaction(function () use ($actor, $targetId, $reason): void {
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            if ($target->id === $actor->id) {
                throw ValidationException::withMessages(['form' => 'You cannot deactivate your own account.']);
            }
            if (! $target->is_active) {
                throw ValidationException::withMessages(['form' => 'This account is already inactive.']);
            }

            $this->rules->assertCanManage($actor, $target);
            $this->lastOwner->assertNotLastActiveOwner($target);

            $target->forceFill(['is_active' => false])->save();

            $this->audit->record(AuditAction::UserDeactivated, $actor, 'user', $target->id, $reason,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
            );
        });

        // Their open sessions end now, not at the next request check.
        UserSessions::revokeAll($targetId);
    }
}
