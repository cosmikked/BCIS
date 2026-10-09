<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\AccountNumberGenerator;
use App\Domain\Subscribers\Enums\AddressType;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\SubscriberAddress;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a service account (FR-SUB-002, FR-SUB-007). It starts PENDING and is
 * not billed until it is activated. The plan price is COPIED into
 * current_rate_centavos here and never looked up again (INV-20), so a later
 * plan price change cannot touch this account. A Combo is one account on one
 * Combo plan.
 *
 * The subscriber row is locked so the subscriber cannot be terminated at the
 * same moment (ChangeSubscriberStatus locks the same row). The plan is read
 * with a shared lock so it cannot be deactivated mid-way.
 */
final class CreateServiceAccount
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountNumberGenerator $numbers,
        private readonly AssignCollector $assignCollector,
    ) {}

    /**
     * @param  array<string, mixed>  $input  subscriber_id, plan_id, service_address_id, billing_start_date,
     *                                       optional billing_due_day (defaults to the subscriber's) and collector_id
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $input): ServiceAccount
    {
        Gate::forUser($actor)->authorize('service.manage');

        $data = Validator::make($input, [
            'subscriber_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
            'service_address_id' => ['required', 'integer'],
            'billing_start_date' => ['required', 'date_format:Y-m-d'],
            'billing_due_day' => ['nullable', 'integer', 'between:1,31'],
            'collector_id' => ['nullable', 'integer'],
        ], [], [
            'plan_id' => 'plan',
            'service_address_id' => 'service address',
            'billing_start_date' => 'billing start date',
            'billing_due_day' => 'billing due day',
        ])->validate();

        // A blank form field arrives as '', which must mean "not given", never id or day 0.
        $dueDay = $this->intOrNull($data['billing_due_day'] ?? null);
        $collectorId = $this->intOrNull($data['collector_id'] ?? null);

        try {
            return DB::transaction(function () use ($actor, $data, $dueDay, $collectorId): ServiceAccount {
                $subscriber = Subscriber::query()->whereKey((int) $data['subscriber_id'])->lockForUpdate()->firstOrFail();

                if ($subscriber->status !== SubscriberStatus::Active) {
                    throw ValidationException::withMessages(['subscriber_id' => 'Only an active subscriber can get a new service account.']);
                }

                $plan = ServicePlan::query()->whereKey((int) $data['plan_id'])->sharedLock()->first();
                if ($plan === null || ! $plan->is_active) {
                    throw ValidationException::withMessages(['plan_id' => 'Choose an active plan.']);
                }

                $address = SubscriberAddress::query()
                    ->whereKey((int) $data['service_address_id'])
                    ->where('subscriber_id', $subscriber->id)
                    ->where('address_type', AddressType::Service->value)
                    ->first();
                if ($address === null) {
                    throw ValidationException::withMessages(['service_address_id' => 'Choose one of this subscriber\'s service addresses.']);
                }

                $account = ServiceAccount::query()->create([
                    'account_no' => $this->numbers->nextServiceAccountNo(),
                    'subscriber_id' => $subscriber->id,
                    'plan_id' => $plan->id,
                    'service_address_id' => $address->id,
                    'status' => ServiceStatus::Pending,
                    'billing_start_date' => $data['billing_start_date'],
                    'billing_due_day' => $dueDay ?? $subscriber->billing_due_day,
                    // INV-20: the rate is a copy, not a reference to the plan.
                    'current_rate_centavos' => $plan->price_centavos,
                    'created_by' => $actor->id,
                ]);

                ServiceEvent::query()->create([
                    'service_account_id' => $account->id,
                    'event_type' => ServiceEventType::Created,
                    'from_status' => null,
                    'to_status' => ServiceStatus::Pending,
                    'actor_user_id' => $actor->id,
                    'notes' => "Created on plan {$plan->code}",
                ]);

                $this->audit->record(AuditAction::ServiceAccountCreated, $actor, 'service_account', $account->id,
                    newValues: [
                        'account_no' => $account->account_no,
                        'subscriber_id' => $subscriber->id,
                        'plan_id' => $plan->id,
                        'service_address_id' => $address->id,
                        'status' => ServiceStatus::Pending->value,
                        'current_rate_centavos' => $plan->price_centavos->centavos,
                        'billing_start_date' => $account->billing_start_date->toDateString(),
                        'billing_due_day' => $account->billing_due_day,
                    ],
                );

                if ($collectorId !== null) {
                    $this->assignCollector->forServiceAccount($actor, $account->id, $collectorId);
                }

                return $account->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['form' => 'The account number was already taken. Try again.']);
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : (int) $value;
    }
}
