<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Money\Money;

/**
 * A page of a subscriber's ledger for a date range. The opening balance is what
 * was owed before the range, so the first running balance on screen continues
 * from it. The closing balance is the balance at the end of the range.
 */
final readonly class LedgerStatement
{
    /** @param  list<LedgerRow>  $rows  this page only */
    public function __construct(
        public array $rows,
        public int $totalRows,
        public Money $openingBalance,
        public Money $closingBalance,
    ) {}
}
