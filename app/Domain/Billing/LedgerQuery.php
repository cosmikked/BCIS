<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads a subscriber's ledger (FR-BIL-016, INV-02). The running balance is
 * never stored: it is computed here with a window function over ALL of the
 * subscriber's entries in the fixed order (entry_date, id), and only then cut
 * to the requested dates. So the balance on a filtered page is the same figure
 * the unfiltered ledger shows for that line, and the page always agrees with
 * the plain sum of debits minus credits (TO-05).
 *
 * Balance = debits - credits: positive means the subscriber owes money.
 */
final class LedgerQuery
{
    public function statement(
        int $subscriberId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        int $perPage = 50,
        int $page = 1,
    ): LedgerStatement {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $fromDate = $from?->toDateString();
        $toDate = $to?->toDateString();

        $inRange = DB::query()
            ->fromSub($this->withRunningBalance($subscriberId), 'l')
            ->when($fromDate !== null, fn ($q) => $q->where('l.entry_date', '>=', $fromDate))
            ->when($toDate !== null, fn ($q) => $q->where('l.entry_date', '<=', $toDate));

        $total = (clone $inRange)->count();

        $rows = $inRange
            ->orderBy('l.entry_date')->orderBy('l.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()
            ->map(fn (object $r) => new LedgerRow(
                id: (int) $r->id,
                entryDate: CarbonImmutable::parse($r->entry_date),
                type: LedgerEntryType::from($r->entry_type),
                description: $r->description,
                referenceType: $r->reference_type,
                referenceId: $r->reference_id === null ? null : (int) $r->reference_id,
                referenceNo: $r->reference_no,
                debit: Money::fromCentavos((int) $r->debit_centavos),
                credit: Money::fromCentavos((int) $r->credit_centavos),
                runningBalance: Money::fromCentavos((int) $r->running),
            ))
            ->all();

        return new LedgerStatement(
            rows: $rows,
            totalRows: $total,
            openingBalance: $fromDate === null ? Money::zero() : $this->balanceAt($subscriberId, before: $fromDate),
            closingBalance: $this->balanceAt($subscriberId, through: $toDate),
        );
    }

    /** What the subscriber owes right now: the plain sum, used to cross-check the running balance. */
    public function currentBalance(int $subscriberId): Money
    {
        return $this->balanceAt($subscriberId);
    }

    /** The running balance of the last ledger line, as the screen would show it. Null when the ledger is empty. */
    public function lastRunningBalance(int $subscriberId): ?Money
    {
        $row = DB::query()
            ->fromSub($this->withRunningBalance($subscriberId), 'l')
            ->orderByDesc('l.entry_date')->orderByDesc('l.id')
            ->first();

        return $row === null ? null : Money::fromCentavos((int) $row->running);
    }

    private function withRunningBalance(int $subscriberId): Builder
    {
        return DB::table('ledger_entries')
            ->where('subscriber_id', $subscriberId)
            ->selectRaw('ledger_entries.*, SUM(debit_centavos - credit_centavos) OVER (ORDER BY entry_date, id) AS running');
    }

    /** Sum of debits minus credits, strictly before one date, through one date, or for everything. */
    private function balanceAt(int $subscriberId, ?string $before = null, ?string $through = null): Money
    {
        $net = DB::table('ledger_entries')
            ->where('subscriber_id', $subscriberId)
            ->when($before !== null, fn ($q) => $q->where('entry_date', '<', $before))
            ->when($through !== null, fn ($q) => $q->where('entry_date', '<=', $through))
            ->selectRaw('COALESCE(SUM(debit_centavos - credit_centavos), 0) AS net')
            ->value('net');

        return Money::fromCentavos((int) $net);
    }
}
