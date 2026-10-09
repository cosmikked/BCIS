<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscribers\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscriber_id
 * @property ContactType $contact_type
 * @property string $value
 * @property bool $is_primary
 */
class SubscriberContact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contact_type' => ContactType::class,
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
