<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Collector;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Collector> */
class CollectorFactory extends Factory
{
    protected $model = Collector::class;

    public function definition(): array
    {
        return [
            'code' => 'COL-'.fake()->unique()->numerify('####'),
            'full_name' => fake()->name(),
            'contact_no' => null,
            'is_active' => true,
        ];
    }
}
