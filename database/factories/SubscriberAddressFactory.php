<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscribers\Enums\AddressType;
use App\Models\Subscriber;
use App\Models\SubscriberAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriberAddress> */
class SubscriberAddressFactory extends Factory
{
    protected $model = SubscriberAddress::class;

    public function definition(): array
    {
        return [
            'subscriber_id' => Subscriber::factory(),
            'address_type' => AddressType::Service,
            'line1' => fake()->buildingNumber().' '.fake()->streetName(),
            'barangay' => 'Barangay '.fake()->numberBetween(1, 20),
            'city' => 'Malaybalay City',
            'landmark' => null,
            'collection_area_id' => null,
        ];
    }
}
