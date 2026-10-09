<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use App\Domain\Services\Enums\ServiceStatus;
use Database\Factories\ServiceAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Never deleted (INV-19). current_rate_centavos is a snapshot of the plan price
 * taken when the account was created (INV-20). The status changes only through
 * ChangeServiceStatus, which also appends a service event.
 *
 * @property int $id
 * @property string $account_no
 * @property int $subscriber_id
 * @property int $plan_id
 * @property int $service_address_id
 * @property ServiceStatus $status
 * @property Carbon|null $activation_date
 * @property Carbon $billing_start_date
 * @property int $billing_due_day
 * @property Money $current_rate_centavos
 * @property int|null $assigned_collector_id
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Subscriber $subscriber
 * @property-read ServicePlan $plan
 * @property-read SubscriberAddress $serviceAddress
 * @property-read Collector|null $collector
 */
class ServiceAccount extends Model
{
    /** @use HasFactory<ServiceAccountFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'activation_date' => 'date',
            'billing_start_date' => 'date',
            'current_rate_centavos' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return BelongsTo<ServicePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class, 'plan_id');
    }

    /** @return BelongsTo<SubscriberAddress, $this> */
    public function serviceAddress(): BelongsTo
    {
        return $this->belongsTo(SubscriberAddress::class, 'service_address_id');
    }

    /** @return BelongsTo<Collector, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class, 'assigned_collector_id');
    }

    /** @return HasMany<ServiceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ServiceEvent::class);
    }

    /** @return HasMany<CollectorAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CollectorAssignment::class);
    }
}
