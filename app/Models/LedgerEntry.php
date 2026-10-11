<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only (INV-02). A correction is a new row. The model refuses updates
 * and deletes as a first guard; the database trigger and revoked privileges are
 * the real ones. Rows are written through LedgerPoster, never built by hand.
 * The running balance is computed from these rows, never stored (FR-BIL-016).
 *
 * @property int $id
 * @property int $subscriber_id
 * @property int|null $service_account_id
 * @property Carbon $entry_date
 * @property Carbon $posted_at
 * @property LedgerEntryType $entry_type
 * @property Money $debit_centavos
 * @property Money $credit_centavos
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $reference_no
 * @property string $description
 * @property int $created_by
 * @property-read Subscriber $subscriber
 */
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'entry_type' => LedgerEntryType::class,
            'debit_centavos' => MoneyCast::class,
            'credit_centavos' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
