<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscribers\Enums\SubscriberStatus;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Never deleted (INV-19): there is no delete method on any Action or route.
 *
 * @property int $id
 * @property string $account_no
 * @property string $full_name
 * @property SubscriberStatus $status
 * @property int|null $collection_area_id
 * @property int $billing_due_day
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Subscriber extends Model
{
    /** @use HasFactory<SubscriberFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => SubscriberStatus::class];
    }

    /** @return BelongsTo<CollectionArea, $this> */
    public function collectionArea(): BelongsTo
    {
        return $this->belongsTo(CollectionArea::class);
    }

    /** @return HasMany<SubscriberContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SubscriberContact::class);
    }

    /** @return HasMany<SubscriberAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(SubscriberAddress::class);
    }

    /** @return HasMany<SubscriberDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SubscriberDocument::class);
    }

    /** @return HasMany<ServiceAccount, $this> */
    public function serviceAccounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }
}
