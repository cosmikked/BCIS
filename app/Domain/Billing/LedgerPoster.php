<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of ledger_entries (INV-02). It never updates anything: a
 * correction is another row. Billing writes debits, payments write credits,
 * adjustments and reversals write counter-entries. Phase 5 reuses this class.
 *
 * It must be called inside the posting Action's transaction (INV-11), so the
 * ledger row, the document it belongs to and the audit record commit or roll
 * back together.
 */
final class LedgerPoster
{
    public function post(LedgerPosting $posting): LedgerEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A ledger entry must be posted inside the Action\'s database transaction.');
        }

        // One side only, and positive. The database CHECK says the same; this is
        // the readable error that comes first.
        $debit = $posting->debit->isPositive();
        $credit = $posting->credit->isPositive();

        if ($debit === $credit || $posting->debit->isNegative() || $posting->credit->isNegative()) {
            throw new InvalidArgumentException('A ledger entry carries exactly one positive amount: a debit or a credit.');
        }

        return LedgerEntry::query()->create([
            'subscriber_id' => $posting->subscriberId,
            'service_account_id' => $posting->serviceAccountId,
            'entry_date' => $posting->entryDate->toDateString(),
            'entry_type' => $posting->type,
            'debit_centavos' => $posting->debit,
            'credit_centavos' => $posting->credit,
            'description' => $posting->description,
            'reference_type' => $posting->referenceType,
            'reference_id' => $posting->referenceId,
            'reference_no' => $posting->referenceNo,
            'created_by' => $posting->actor->id,
        ]);
    }
}
