<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Money\Money;
use App\Models\Invoice;
use App\Models\ServiceAccount;
use Carbon\CarbonImmutable;

/**
 * Decides, for one period, which accounts are billed and for how much
 * (FR-BIL-001, FR-BIL-002, FR-BIL-006, FR-BIL-007). It writes nothing. The
 * preview and the generator both call it, so what the user sees in the preview
 * is exactly what Generate creates.
 */
final class BillingPlanner
{
    public function __construct(
        private readonly BillingEligibility $eligibility,
        private readonly DueDateCalculator $dueDates,
    ) {}

    public function plan(CarbonImmutable $period): BillingPlan
    {
        $billed = $this->alreadyBilled($period);
        $lines = [];
        $skipped = [];

        ServiceAccount::query()
            ->with(['subscriber', 'plan'])
            ->chunkById(500, function ($accounts) use ($period, $billed, &$lines, &$skipped): void {
                foreach ($accounts as $account) {
                    $reason = $this->eligibility->skipReason($account, $period, isset($billed[$account->id]));

                    if ($reason !== null) {
                        $skipped[] = new SkippedAccount($account, $reason);

                        continue;
                    }

                    $lines[] = $this->lineFor($account, $period);
                }
            });

        return new BillingPlan($period, $lines, $skipped);
    }

    private function lineFor(ServiceAccount $account, CarbonImmutable $period): BillingLine
    {
        // PRD 19 #6: the installation fee is an item on the FIRST invoice. It is
        // charged once; installation_fee_billed_at is set when that invoice is finalized.
        $fee = $account->installation_fee_billed_at === null
            ? $account->installation_fee_centavos
            : Money::zero();

        return new BillingLine(
            account: $account,
            subscriptionDescription: $account->plan->name.' - '.$period->format('F Y'),
            rate: $account->current_rate_centavos,
            installationFee: $fee,
            invoiceDate: $period,
            dueDate: $this->dueDates->forPeriod($period, $account->billing_due_day),
        );
    }

    /**
     * Accounts that already hold a non-void MONTHLY invoice for the period,
     * DRAFT ones included. The partial unique index is the real guard (INV-09);
     * this just lets the preview and the generator skip them politely.
     *
     * @return array<int, bool> a set: service account id => true
     */
    private function alreadyBilled(CarbonImmutable $period): array
    {
        return Invoice::query()
            ->where('invoice_type', InvoiceType::Monthly->value)
            ->where('billing_period', $period->toDateString())
            ->where('status', '<>', InvoiceStatus::Void->value)
            ->pluck('service_account_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
