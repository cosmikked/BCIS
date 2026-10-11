<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\AllocationTotals;
use App\Domain\Money\Money;

/** Phase 4 stand-in: no payment exists yet, so nothing is allocated. Phase 5 replaces the binding. */
final class NullAllocationTotals implements AllocationTotals
{
    public function forInvoices(array $invoiceIds): array
    {
        return array_fill_keys($invoiceIds, Money::zero());
    }
}
