<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An adjustment or void REQUEST and, once decided, its permanent record
 * (FR-BIL-009). A pending row has no effect on any balance; approval is what
 * posts the ledger counter-entry. Decided rows and deletes are refused here and
 * again by the database trigger. Written only by the Billing Actions.
 *
 * @property int $id
 * @property string $adjustment_no
 * @property int $subscriber_id
 * @property int $service_account_id
 * @property int $invoice_id
 * @property AdjustmentKind $kind
 * @property Money $amount_centavos
 * @property string $reason
 * @property AdjustmentStatus $status
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property int|null $approved_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Invoice $invoice
 * @property-read User $requester
 * @property-read User|null $approver
 */
class Adjustment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => AdjustmentKind::class,
            'amount_centavos' => MoneyCast::class,
            'status' => AdjustmentStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            if ($adjustment->getOriginal('status') !== AdjustmentStatus::Pending) {
                throw new LogicException('A decided adjustment cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Adjustments are never deleted.'));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
