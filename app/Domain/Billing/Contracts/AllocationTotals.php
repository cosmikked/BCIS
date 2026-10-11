<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Money\Money;

/**
 * Net amount of payments applied to invoices (INV-06): allocations minus the
 * compensating rows a reversal writes. Payments arrive in Phase 5, which binds
 * an implementation that reads payment_allocations. Until then
 * NullAllocationTotals reports zero, so the balance rules already work with
 * adjustments alone.
 */
interface AllocationTotals
{
    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, Money> net allocated amount per invoice id; every requested id is present
     */
    public function forInvoices(array $invoiceIds): array;
}
