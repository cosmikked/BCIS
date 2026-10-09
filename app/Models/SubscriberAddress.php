<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscribers\Enums\AddressType;
use Database\Factories\SubscriberAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscriber_id
 * @property AddressType $address_type
 * @property string $line1
 * @property string|null $barangay
 * @property string $city
 * @property string|null $landmark
 * @property int|null $collection_area_id
 */
class SubscriberAddress extends Model
{
    /** @use HasFactory<SubscriberAddressFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['address_type' => AddressType::class];
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return BelongsTo<CollectionArea, $this> */
    public function collectionArea(): BelongsTo
    {
        return $this->belongsTo(CollectionArea::class);
    }

    /** One line for lists and the duplicate warning. */
    public function oneLine(): string
    {
        return implode(', ', array_filter([$this->line1, $this->barangay, $this->city]));
    }
}
