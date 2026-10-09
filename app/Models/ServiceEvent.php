<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only (INV-21). The model refuses updates and deletes as a first guard;
 * the database trigger and revoked privileges are the real ones. Rows are
 * written by the Actions that change a service account.
 *
 * @property int $id
 * @property int $service_account_id
 * @property ServiceEventType $event_type
 * @property ServiceStatus|null $from_status
 * @property ServiceStatus|null $to_status
 * @property Carbon $occurred_at
 * @property int $actor_user_id
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 */
class ServiceEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_type' => ServiceEventType::class,
            'from_status' => ServiceStatus::class,
            'to_status' => ServiceStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Service events are insert-only.'));
        static::deleting(fn () => throw new LogicException('Service events are insert-only.'));
    }

    /** @return BelongsTo<ServiceAccount, $this> */
    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
