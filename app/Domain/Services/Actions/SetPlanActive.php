<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Retires or restores a plan (FR-SUB-005). A plan is never deleted; an inactive
 * plan only stops being offered for NEW service accounts. Existing accounts on
 * it are untouched.
 */
final class SetPlanActive
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $planId, bool $active, string $reason): ServicePlan
    {
        Gate::forUser($actor)->authorize('plan.manage');
        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $planId, $active, $reason): ServicePlan {
            $plan = ServicePlan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();

            if ($plan->is_active === $active) {
                throw ValidationException::withMessages([
                    'form' => $active ? 'The plan is already active.' : 'The plan is already inactive.',
                ]);
            }

            $plan->forceFill(['is_active' => $active])->save();

            $this->audit->record($active ? AuditAction::PlanActivated : AuditAction::PlanDeactivated,
                $actor, 'service_plan', $plan->id, $reason,
                oldValues: ['is_active' => ! $active],
                newValues: ['is_active' => $active],
            );

            return $plan;
        });
    }
}
