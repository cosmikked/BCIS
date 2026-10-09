<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Services\Actions\CreateServiceAccount;
use App\Domain\Subscribers\Actions\RegisterSubscriber;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\User;

/** Test data builders that go through the real Actions, like live data does. */
final class SubscriberData
{
    private static int $counter = 0;

    /**
     * Valid subscriber form input with a phone number unique to this call.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function input(array $overrides = []): array
    {
        self::$counter++;

        return [...[
            'full_name' => 'Test Subscriber '.self::$counter,
            'collection_area_id' => null,
            'billing_due_day' => 5,
            'notes' => null,
            'contacts' => [['contact_type' => 'MOBILE', 'value' => sprintf('0999%07d', self::$counter), 'is_primary' => true]],
            'addresses' => [[
                'address_type' => 'SERVICE',
                'line1' => self::$counter.' Rizal Street',
                'barangay' => 'Poblacion',
                'city' => 'Malaybalay City',
            ]],
        ], ...$overrides];
    }

    /** @param  array<string, mixed>  $overrides */
    public static function subscriber(User $actor, array $overrides = []): Subscriber
    {
        return app(RegisterSubscriber::class)->handle($actor, self::input($overrides));
    }

    /** @param  array<string, mixed>  $overrides  extra input for CreateServiceAccount */
    public static function account(User $actor, ?Subscriber $subscriber = null, ?ServicePlan $plan = null, array $overrides = []): ServiceAccount
    {
        $subscriber ??= self::subscriber($actor);
        $plan ??= ServicePlan::factory()->create();

        return app(CreateServiceAccount::class)->handle($actor, [...[
            'subscriber_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'service_address_id' => $subscriber->addresses()->orderBy('id')->firstOrFail()->id,
            'billing_start_date' => '2026-10-01',
        ], ...$overrides]);
    }
}
