<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Money;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\SubscriberAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds rows directly for tests. Real accounts are created by
 * CreateServiceAccount, which copies the plan rate and writes the first event.
 *
 * @extends Factory<ServiceAccount>
 */
class ServiceAccountFactory extends Factory
{
    protected $model = ServiceAccount::class;

    public function definition(): array
    {
        return [
            'account_no' => 'SVC-T'.fake()->unique()->numerify('######'),
            'subscriber_id' => Subscriber::factory(),
            // The service address belongs to the same subscriber.
            'service_address_id' => fn (array $attributes) => SubscriberAddress::factory()->create([
                'subscriber_id' => $attributes['subscriber_id'],
            ])->id,
            'plan_id' => ServicePlan::factory(),
            'status' => ServiceStatus::Pending,
            'activation_date' => null,
            'billing_start_date' => '2026-10-01',
            'billing_due_day' => 5,
            'current_rate_centavos' => Money::fromCentavos(99900),
            'assigned_collector_id' => null,
            'created_by' => SubscriberFactory::anyUserId(),
        ];
    }

    public function status(ServiceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
