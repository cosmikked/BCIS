<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Financial record (INV-05, INV-09, INV-19). Never deleted once numbered and
 * never given a stored OVERDUE value. A finalized invoice keeps its number,
 * total, dates and owner for good; only status (recomputed from the balance)
 * and the void fields move. The database trigger enforces this for every
 * writer; this hook only fails earlier with a readable message.
 *
 * @property int $id
 * @property string|null $invoice_no
 * @property int|null $billing_cycle_id
 * @property int $service_account_id
 * @property int $subscriber_id
 * @property InvoiceType $invoice_type
 * @property Carbon|null $billing_period
 * @property Carbon $invoice_date
 * @property Carbon $due_date
 * @property Money $total_centavos
 * @property InvoiceStatus $status
 * @property Carbon|null $finalized_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read BillingCycle|null $cycle
 * @property-read ServiceAccount $serviceAccount
 * @property-read Subscriber $subscriber
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Adjustment> $adjustments
 */
class Invoice extends Model
{
    /** Columns that may still change after finalization. */
    private const MUTABLE_AFTER_FINALIZATION = ['status', 'voided_at', 'void_reason', 'updated_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_type' => InvoiceType::class,
            'billing_period' => 'date',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'total_centavos' => MoneyCast::class,
            'status' => InvoiceStatus::class,
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $was = $invoice->getOriginal('status');

            if ($was instanceof InvoiceStatus && $was->isFinalized()) {
                $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_FINALIZATION);

                if ($illegal !== []) {
                    throw new LogicException('A finalized invoice cannot be changed: '.implode(', ', $illegal).'. Use an adjustment or a void.');
                }
            }
        });
        static::deleting(function (self $invoice): void {
            if ($invoice->status->isFinalized()) {
                throw new LogicException('A finalized invoice is never deleted.');
            }
        });
    }

    /** @return BelongsTo<BillingCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class, 'billing_cycle_id');
    }

    /** @return BelongsTo<ServiceAccount, $this> */
    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<Adjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }
}
