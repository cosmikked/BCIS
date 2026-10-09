<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Money;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServicePlan> */
class ServicePlanFactory extends Factory
{
    protected $model = ServicePlan::class;

    public function definition(): array
    {
        return [
            'code' => 'PLAN-'.fake()->unique()->numerify('####'),
            'name' => 'Test plan '.fake()->unique()->numerify('###'),
            'service_type_id' => self::typeId('INTERNET'),
            'price_centavos' => Money::fromCentavos(99900),
            'installation_fee_centavos' => Money::fromCentavos(50000),
            'speed_mbps' => 20,
            'channel_count' => null,
            'description' => null,
            'is_active' => true,
        ];
    }

    public function cable(): static
    {
        return $this->state(fn () => [
            'service_type_id' => self::typeId('CABLE'),
            'speed_mbps' => null,
            'channel_count' => 120,
        ]);
    }

    public function combo(): static
    {
        return $this->state(fn () => [
            'service_type_id' => self::typeId('COMBO'),
            'speed_mbps' => 20,
            'channel_count' => 120,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    private static function typeId(string $code): int
    {
        return (int) ServiceType::query()->where('code', $code)->value('id');
    }
}
