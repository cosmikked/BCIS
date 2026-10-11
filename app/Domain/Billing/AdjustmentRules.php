<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What an adjustment or void may do to an invoice (FR-BIL-009, INV-06). Used
 * twice: when a request is made, and again when it is approved, because the
 * invoice can change in between. An approval that no longer fits is refused
 * rather than posted. The caller must hold the invoice row lock.
 */
final class AdjustmentRules
{
    public function __construct(private readonly InvoiceBalanceCalculator $balances) {}

    /**
     * The amount of a request. A void cancels exactly what is still owed, so its
     * amount is the current balance and any typed value is ignored.
     *
     * @throws ValidationException
     */
    public function resolveAmount(Invoice $invoice, AdjustmentKind $kind, ?string $pesos): Money
    {
        if ($kind === AdjustmentKind::Void) {
            return $this->balances->balance($invoice);
        }

        try {
            $amount = Money::fromPesos((string) $pesos);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid amount in pesos, for example 250.00.']);
        }

        return $amount;
    }

    /** @throws ValidationException */
    public function assertApplicable(Invoice $invoice, AdjustmentKind $kind, Money $amount): void
    {
        if (! $invoice->status->isFinalized() || $invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages([
                'invoice' => $invoice->status === InvoiceStatus::Void
                    ? 'This invoice is void.'
                    : 'Only a finalized invoice can be adjusted. Draft invoices are discarded instead.',
            ]);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages([
                'amount' => $kind === AdjustmentKind::Void
                    ? 'This invoice has nothing outstanding to void.'
                    : 'The amount must be greater than zero.',
            ]);
        }

        $figures = $this->balances->figuresFor($invoice);
        $balance = $figures->balance();

        if ($kind === AdjustmentKind::Void) {
            // A paid-on invoice is corrected by reversing the payment first (Phase 5).
            if ($figures->allocated->isPositive()) {
                throw ValidationException::withMessages([
                    'invoice' => 'This invoice has payments applied. Reverse the payments before voiding it.',
                ]);
            }
            if (! $amount->equals($balance)) {
                throw ValidationException::withMessages([
                    'amount' => 'The invoice balance changed since this void was requested. Request it again.',
                ]);
            }

            return;
        }

        // A credit or write-off may not push the balance below zero (INV-06).
        if (! $kind->increasesBalance() && $amount->greaterThan($balance)) {
            throw ValidationException::withMessages([
                'amount' => 'The amount is more than the invoice balance of '.$balance->format().'.',
            ]);
        }
    }
}
