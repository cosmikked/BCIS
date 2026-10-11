<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One cycle per billed month (FR-BIL-001). Never deleted: discarding drafts
 * empties the cycle, it does not remove it. A FINALIZED cycle is not edited.
 * The database triggers are the real guard; the hooks fail early with a clearer
 * message. Written only by the Billing Actions.
 *
 * @property int $id
 * @property Carbon $period_month
 * @property BillingCycleStatus $status
 * @property int $generated_by
 * @property Carbon $generated_at
 * @property int|null $finalized_by
 * @property Carbon|null $finalized_at
 * @property int $invoice_count
 * @property Money $total_centavos
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Invoice> $invoices
 */
class BillingCycle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => BillingCycleStatus::class,
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'total_centavos' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cycle): void {
            if ($cycle->getOriginal('status') === BillingCycleStatus::Finalized) {
                throw new LogicException('A finalized billing cycle cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Billing cycles are never deleted.'));
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
