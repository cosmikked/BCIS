<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Models\Invoice;
use App\Models\User;

/**
 * FR-BIL-012. Phase 5 plugs in here: when an invoice is finalized, the
 * subscriber's unapplied credit (advance payment) is applied to it, oldest due
 * first, by writing allocation rows.
 *
 * FinalizeBillingCycle calls apply() once per invoice, inside the finalization
 * transaction, AFTER the invoice is numbered and its ledger debit is posted and
 * BEFORE its status is resolved. So an implementation must only write
 * allocation rows (no ledger row: the payment's credit is already on the
 * ledger) and must not change the invoice itself. A thrown exception rolls the
 * whole finalization back (INV-11).
 */
interface CustomerCreditApplier
{
    public function apply(Invoice $invoice, User $actor): void;
}
