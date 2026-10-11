<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\BillingLine;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingPlanner;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Enums\SkipReason;
use App\Domain\Billing\GenerationResult;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates the DRAFT invoices and their items for one month (FR-BIL-001,
 * FR-BIL-003, FR-BIL-004). Nothing here is numbered or posted to the ledger;
 * that is FinalizeBillingCycle.
 *
 * Running it twice, or from two PCs at once, cannot bill an account twice
 * (INV-09, AT-11), in three layers:
 *  1. the billing_cycles row for the month is created with ON CONFLICT DO
 *     NOTHING and then locked, so a second run waits for the first to commit
 *     and then sees its invoices;
 *  2. the planner skips accounts that already hold a non-void MONTHLY invoice;
 *  3. the partial unique index on invoices rejects a duplicate no matter what,
 *     and that rejection is treated as "already billed", not as a crash.
 *
 * It is one transaction for the whole month: all drafts or none.
 */
final class GenerateBillingCycle
{
    public function __construct(
        private readonly BillingPlanner $planner,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, CarbonInterface|string $period): GenerationResult
    {
        Gate::forUser($actor)->authorize('billing.generate');

        $period = BillingPeriod::billable($period);

        return DB::transaction(function () use ($actor, $period): GenerationResult {
            // Layer 1. insertOrIgnore is INSERT ... ON CONFLICT DO NOTHING; if
            // another run is mid-insert, this waits for it to commit or roll back.
            DB::table('billing_cycles')->insertOrIgnore([
                'period_month' => $period->toDateString(),
                'status' => BillingCycleStatus::Draft->value,
                'generated_by' => $actor->id,
            ]);

            $cycle = BillingCycle::query()
                ->where('period_month', $period->toDateString())
                ->lockForUpdate()
                ->firstOrFail();

            if ($cycle->status === BillingCycleStatus::Finalized) {
                throw ValidationException::withMessages([
                    'period' => 'Billing for '.$period->format('F Y').' is already finalized and cannot be generated again.',
                ]);
            }

            // Layer 2. Read after the lock, so a run that just committed is visible.
            $plan = $this->planner->plan($period);

            $created = 0;
            $raced = 0;
            foreach ($plan->lines as $line) {
                $this->createDraft($cycle, $line) ? $created++ : $raced++;
            }

            $totals = Invoice::query()
                ->where('billing_cycle_id', $cycle->id)
                ->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_centavos), 0) AS total')
                ->toBase()
                ->first();

            $cycle->forceFill([
                'invoice_count' => (int) $totals->invoice_count,
                'total_centavos' => Money::fromCentavos((int) $totals->total),
            ])->save();

            $alreadyBilled = $plan->skippedCount(SkipReason::AlreadyBilled) + $raced;
            $ineligible = count($plan->skipped) - $plan->skippedCount(SkipReason::AlreadyBilled);

            $this->audit->record(AuditAction::BillingCycleGenerated, $actor, 'billing_cycle', $cycle->id,
                newValues: [
                    'period' => $period->toDateString(),
                    'created' => $created,
                    'already_billed' => $alreadyBilled,
                    'ineligible' => $ineligible,
                    'cycle_invoice_count' => $cycle->invoice_count,
                    'cycle_total_centavos' => $cycle->total_centavos->centavos,
                ],
            );

            return new GenerationResult($cycle, $created, $alreadyBilled, $ineligible);
        });
    }

    /**
     * Layer 3. Each insert runs in its own savepoint, so a rejected duplicate
     * rolls back only itself and the rest of the month still goes through.
     *
     * @return bool true when the draft was created, false when the database said "already billed"
     */
    private function createDraft(BillingCycle $cycle, BillingLine $line): bool
    {
        try {
            DB::transaction(function () use ($cycle, $line): void {
                $invoice = Invoice::query()->create([
                    'invoice_no' => null,
                    'billing_cycle_id' => $cycle->id,
                    'service_account_id' => $line->account->id,
                    'subscriber_id' => $line->account->subscriber_id,
                    'invoice_type' => InvoiceType::Monthly,
                    'billing_period' => $cycle->period_month->toDateString(),
                    'invoice_date' => $line->invoiceDate->toDateString(),
                    'due_date' => $line->dueDate->toDateString(),
                    'total_centavos' => $line->total(),
                    'status' => InvoiceStatus::Draft,
                ]);

                // INV-20: the billed rate is kept on the item, so a later plan price change never touches it.
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceItemType::Subscription,
                    'description' => $line->subscriptionDescription,
                    'amount_centavos' => $line->rate,
                    'plan_id' => $line->account->plan_id,
                ]);

                if ($line->hasInstallationFee()) {
                    InvoiceItem::query()->create([
                        'invoice_id' => $invoice->id,
                        'item_type' => InvoiceItemType::Installation,
                        'description' => 'Installation fee',
                        'amount_centavos' => $line->installationFee,
                        'plan_id' => null,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
