<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Domain\Billing\DocumentNumberGenerator;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\InvoiceStatusResolver;
use App\Domain\Billing\LedgerPoster;
use App\Domain\Billing\LedgerPosting;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Turns a month's DRAFT invoices into real ones (FR-BIL-005, INV-05, INV-10,
 * INV-11). ONE database transaction, all or nothing. For every invoice, in
 * order of id:
 *   1. take its number from invoice_no_seq and set it UNPAID (now immutable);
 *   2. post the ledger debit;
 *   3. mark the account's installation fee as billed, if it was on this invoice;
 *   4. let the customer-credit hook apply advance payments (FR-BIL-012);
 *   5. re-resolve the status from the balance.
 * Then the cycle is marked FINALIZED and one audit record is written.
 *
 * If anything throws, the transaction rolls back: no numbered invoice, no ledger
 * row, no audit row survives, and the drafts are still drafts. The sequence
 * values drawn meanwhile are simply skipped (gaps are fine, reuse is not).
 *
 * The cycle row is locked first, so two PCs pressing Finalize cannot both
 * number the same invoices: the second waits, then finds the cycle finalized.
 */
final class FinalizeBillingCycle
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly LedgerPoster $ledger,
        private readonly CustomerCreditApplier $creditApplier,
        private readonly InvoiceBalanceCalculator $balances,
        private readonly InvoiceStatusResolver $statuses,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $billingCycleId): BillingCycle
    {
        Gate::forUser($actor)->authorize('billing.generate');

        return DB::transaction(function () use ($actor, $billingCycleId): BillingCycle {
            $cycle = BillingCycle::query()->whereKey($billingCycleId)->lockForUpdate()->firstOrFail();

            if ($cycle->status === BillingCycleStatus::Finalized) {
                throw ValidationException::withMessages(['cycle' => 'This billing cycle is already finalized.']);
            }

            $drafts = Invoice::query()
                ->with('items')
                ->where('billing_cycle_id', $cycle->id)
                ->where('status', InvoiceStatus::Draft->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($drafts->isEmpty()) {
                throw ValidationException::withMessages(['cycle' => 'There are no draft invoices to finalize. Generate the month first.']);
            }

            $total = Money::zero();
            $firstNo = null;
            $lastNo = null;

            foreach ($drafts as $invoice) {
                $this->finalizeInvoice($actor, $invoice);

                $total = $total->add($invoice->total_centavos);
                $firstNo ??= $invoice->invoice_no;
                $lastNo = $invoice->invoice_no;
            }

            $cycle->forceFill([
                'status' => BillingCycleStatus::Finalized,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
                'invoice_count' => $drafts->count(),
                'total_centavos' => $total,
            ])->save();

            $this->audit->record(AuditAction::BillingCycleFinalized, $actor, 'billing_cycle', $cycle->id,
                oldValues: ['status' => BillingCycleStatus::Draft->value],
                newValues: [
                    'status' => BillingCycleStatus::Finalized->value,
                    'period' => $cycle->period_month->toDateString(),
                    'invoice_count' => $drafts->count(),
                    'total_centavos' => $total->centavos,
                    'first_invoice_no' => $firstNo,
                    'last_invoice_no' => $lastNo,
                ],
            );

            return $cycle;
        });
    }

    private function finalizeInvoice(User $actor, Invoice $invoice): void
    {
        // FR-BIL-006: the total is the sum of the items. Generate guarantees it;
        // a mismatch here means something wrote a draft by hand, so stop.
        $itemSum = $invoice->items->reduce(fn (Money $sum, $item) => $sum->add($item->amount_centavos), Money::zero());
        if (! $itemSum->equals($invoice->total_centavos)) {
            throw new LogicException("Invoice {$invoice->id} total does not equal the sum of its items.");
        }

        // 1. Number it and make it real. From this UPDATE on, the database
        // trigger treats the invoice as immutable (INV-05).
        $invoice->forceFill([
            'invoice_no' => $this->numbers->nextInvoiceNo(),
            'status' => InvoiceStatus::Unpaid,
            'finalized_at' => now(),
        ])->save();

        // 2. The ledger debit, dated on the invoice date.
        $this->ledger->post(new LedgerPosting(
            type: LedgerEntryType::Invoice,
            subscriberId: $invoice->subscriber_id,
            serviceAccountId: $invoice->service_account_id,
            entryDate: $invoice->invoice_date,
            debit: $invoice->total_centavos,
            credit: Money::zero(),
            description: 'Invoice '.$invoice->invoice_no.': '.$invoice->items->pluck('description')->implode(' + '),
            referenceType: 'invoice',
            referenceId: $invoice->id,
            referenceNo: $invoice->invoice_no,
            actor: $actor,
        ));

        // 3. The installation fee is charged once, on the first invoice (PRD 19 #6).
        if ($invoice->items->contains(fn ($item) => $item->item_type === InvoiceItemType::Installation)) {
            ServiceAccount::query()->whereKey($invoice->service_account_id)->lockForUpdate()->firstOrFail()
                ->forceFill(['installation_fee_billed_at' => now()])->save();
        }

        // 4. Phase 5 applies the subscriber's advance credit here, by writing
        // allocation rows. Today this does nothing.
        $this->creditApplier->apply($invoice, $actor);

        // 5. The status follows the balance, so an invoice fully covered by
        // credit leaves here PAID, not UNPAID (INV-06).
        $status = $this->statuses->statusFor(InvoiceStatus::Unpaid, $this->balances->figuresFor($invoice));
        if ($status !== InvoiceStatus::Unpaid) {
            $invoice->forceFill(['status' => $status])->save();
        }
    }
}
