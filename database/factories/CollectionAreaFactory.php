<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CollectionArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CollectionArea> */
class CollectionAreaFactory extends Factory
{
    protected $model = CollectionArea::class;

    public function definition(): array
    {
        return [
            'code' => 'AREA-'.fake()->unique()->numerify('####'),
            'name' => fake()->unique()->streetName(),
            'is_active' => true,
        ];
    }
}
