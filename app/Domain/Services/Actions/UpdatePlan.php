<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Domain\Services\PlanInput;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits a plan (FR-SUB-005). A price change affects FUTURE billing only
 * (FR-SUB-006, INV-20): this Action touches service_plans and nothing else, so
 * every service account keeps the rate it copied when it was created. The code
 * and service type are fixed once the plan exists.
 */
final class UpdatePlan
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $planId, array $input, string $reason): ServicePlan
    {
        Gate::forUser($actor)->authorize('plan.manage');
        $reason = Reason::require($reason);

        // The service type is not editable, so the form value is replaced by the stored one.
        $existingType = ServicePlan::query()->whereKey($planId)->value('service_type_id');
        $data = PlanInput::validate([...$input, 'service_type_id' => $existingType]);

        return DB::transaction(function () use ($actor, $planId, $data, $reason): ServicePlan {
            $plan = ServicePlan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();

            $old = $this->snapshot($plan);

            $plan->forceFill([
                'name' => $data['name'],
                'price_centavos' => $data['price'],
                'installation_fee_centavos' => $data['installation_fee'],
                'speed_mbps' => $data['speed_mbps'],
                'channel_count' => $data['channel_count'],
                'description' => $data['description'],
            ])->save();

            $this->audit->record(AuditAction::PlanUpdated, $actor, 'service_plan', $plan->id, $reason,
                oldValues: $old,
                newValues: $this->snapshot($plan),
            );

            return $plan;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ServicePlan $plan): array
    {
        return [
            'name' => $plan->name,
            'price_centavos' => $plan->price_centavos->centavos,
            'installation_fee_centavos' => $plan->installation_fee_centavos->centavos,
            'speed_mbps' => $plan->speed_mbps,
            'channel_count' => $plan->channel_count,
            'description' => $plan->description,
        ];
    }
}
