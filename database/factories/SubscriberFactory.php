<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * Builds rows directly for tests. Real subscribers are created by
 * RegisterSubscriber, which takes the account number from the sequence.
 *
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    protected $model = Subscriber::class;

    public function definition(): array
    {
        return [
            'account_no' => 'SUB-T'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'status' => SubscriberStatus::Active,
            'collection_area_id' => null,
            'billing_due_day' => 5,
            'notes' => null,
            'created_by' => self::anyUserId(),
        ];
    }

    public function status(SubscriberStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public static function anyUserId(): int
    {
        $id = User::query()->orderBy('id')->value('id');

        return is_int($id) ? $id : throw new LogicException('Create a user before using subscriber factories.');
    }
}
