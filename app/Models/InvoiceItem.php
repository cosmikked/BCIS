<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Line of an invoice (FR-BIL-006). The billed rate lives here, so a later plan
 * price change never touches it (INV-20). Items of a finalized invoice are
 * immutable: the database trigger blocks insert, update and delete, and the
 * hooks below fail earlier with a readable message.
 *
 * @property int $id
 * @property int $invoice_id
 * @property InvoiceItemType $item_type
 * @property string $description
 * @property Money $amount_centavos
 * @property int|null $plan_id
 * @property Carbon $created_at
 * @property-read Invoice $invoice
 */
class InvoiceItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'item_type' => InvoiceItemType::class,
            'amount_centavos' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $invoice = Invoice::query()->find($item->getOriginal('invoice_id') ?? $item->invoice_id);

            if ($invoice !== null && $invoice->status->isFinalized()) {
                throw new LogicException('Items of a finalized invoice cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
