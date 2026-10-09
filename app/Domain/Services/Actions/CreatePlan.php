<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Services\PlanInput;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Creates a plan (FR-SUB-005). The code is unique and cannot be edited later. */
final class CreatePlan
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): ServicePlan
    {
        Gate::forUser($actor)->authorize('plan.manage');

        $code = strtoupper(trim((string) Validator::make($input, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
        ], ['code.regex' => 'Use letters, digits, dashes and underscores only.'])->validate()['code']));

        $data = PlanInput::validate($input);

        try {
            return DB::transaction(function () use ($actor, $code, $data): ServicePlan {
                $plan = ServicePlan::query()->create([
                    'code' => $code,
                    'name' => $data['name'],
                    'service_type_id' => $data['service_type_id'],
                    'price_centavos' => $data['price'],
                    'installation_fee_centavos' => $data['installation_fee'],
                    'speed_mbps' => $data['speed_mbps'],
                    'channel_count' => $data['channel_count'],
                    'description' => $data['description'],
                    'is_active' => true,
                ]);

                $this->audit->record(AuditAction::PlanCreated, $actor, 'service_plan', $plan->id,
                    newValues: [
                        'code' => $code,
                        'name' => $plan->name,
                        'service_type_id' => $plan->service_type_id,
                        'price_centavos' => $data['price']->centavos,
                        'installation_fee_centavos' => $data['installation_fee']->centavos,
                    ],
                );

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'A plan with this code already exists.']);
        }
    }
}
