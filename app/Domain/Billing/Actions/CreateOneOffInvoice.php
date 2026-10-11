<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Domain\Billing\DocumentNumberGenerator;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\InvoiceStatusResolver;
use App\Domain\Billing\LedgerPoster;
use App\Domain\Billing\LedgerPosting;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A one-off invoice for a fee outside the monthly bill, such as a reconnection or
 * installation fee (FR-BIL-010). It has no billing cycle and no billing period,
 * so it sits outside the monthly uniqueness rule and can coexist with that
 * month's regular invoice. It is numbered, debited to the ledger and made
 * immutable at once, in one transaction, the same way FinalizeBillingCycle does
 * for a monthly one.
 *
 * Phase 7 calls this for the reconnection fee.
 */
final class CreateOneOffInvoice
{
    /** Item types a one-off may carry. Penalties wait for the penalty rule (PRD 19 #4); discounts belong on the monthly bill. */
    private const ALLOWED = [InvoiceItemType::Installation, InvoiceItemType::Reconnection, InvoiceItemType::Other];

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly LedgerPoster $ledger,
        private readonly CustomerCreditApplier $creditApplier,
        private readonly InvoiceBalanceCalculator $balances,
        private readonly InvoiceStatusResolver $statuses,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{type: InvoiceItemType, description: string, amount: string}>  $items  amounts in pesos text
     * @param  string|null  $dueDate  Y-m-d; when null the invoice is due on the day it is issued (a flagged default, PRD 19 does not set one)
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $serviceAccountId, array $items, string $reason, ?string $dueDate = null): Invoice
    {
        Gate::forUser($actor)->authorize('billing.generate');

        $reason = Reason::require($reason);
        $lines = $this->validatedLines($items);
        $today = CarbonImmutable::today();
        $due = $this->validatedDueDate($dueDate, $today);

        return DB::transaction(function () use ($actor, $serviceAccountId, $lines, $reason, $today, $due): Invoice {
            $account = ServiceAccount::query()->whereKey($serviceAccountId)->lockForUpdate()->firstOrFail();

            $total = array_reduce($lines, fn (Money $sum, array $line) => $sum->add($line['amount']), Money::zero());

            // Items can only be written while the invoice is a draft (INV-05), so
            // it is created as one and numbered at the end of this transaction.
            $invoice = Invoice::query()->create([
                'invoice_no' => null,
                'billing_cycle_id' => null,
                'service_account_id' => $account->id,
                'subscriber_id' => $account->subscriber_id,
                'invoice_type' => InvoiceType::OneOff,
                'billing_period' => null,
                'invoice_date' => $today->toDateString(),
                'due_date' => $due->toDateString(),
                'total_centavos' => $total,
                'status' => InvoiceStatus::Draft,
            ]);

            foreach ($lines as $line) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'item_type' => $line['type'],
                    'description' => $line['description'],
                    'amount_centavos' => $line['amount'],
                    'plan_id' => null,
                ]);
            }

            $invoice->forceFill([
                'invoice_no' => $this->numbers->nextInvoiceNo(),
                'status' => InvoiceStatus::Unpaid,
                'finalized_at' => now(),
            ])->save();

            $this->ledger->post(new LedgerPosting(
                type: LedgerEntryType::Invoice,
                subscriberId: $invoice->subscriber_id,
                serviceAccountId: $invoice->service_account_id,
                entryDate: $today,
                debit: $total,
                credit: Money::zero(),
                description: 'Invoice '.$invoice->invoice_no.': '.implode(' + ', array_column($lines, 'description')),
                referenceType: 'invoice',
                referenceId: $invoice->id,
                referenceNo: $invoice->invoice_no,
                actor: $actor,
            ));

            // If the installation fee is charged here, the first monthly invoice
            // must not charge it again.
            $chargesInstallation = array_filter($lines, fn (array $line) => $line['type'] === InvoiceItemType::Installation) !== [];
            if ($chargesInstallation && $account->installation_fee_billed_at === null) {
                $account->forceFill(['installation_fee_billed_at' => now()])->save();
            }

            $invoice->load('items');
            $this->creditApplier->apply($invoice, $actor);

            $status = $this->statuses->statusFor(InvoiceStatus::Unpaid, $this->balances->figuresFor($invoice));
            if ($status !== InvoiceStatus::Unpaid) {
                $invoice->forceFill(['status' => $status])->save();
            }

            $this->audit->record(AuditAction::OneOffInvoiceCreated, $actor, 'invoice', $invoice->id, $reason,
                newValues: [
                    'invoice_no' => $invoice->invoice_no,
                    'service_account_id' => $account->id,
                    'total_centavos' => $total->centavos,
                    'due_date' => $due->toDateString(),
                    'items' => array_map(fn (array $line) => [
                        'type' => $line['type']->value,
                        'description' => $line['description'],
                        'amount_centavos' => $line['amount']->centavos,
                    ], $lines),
                ],
            );

            return $invoice;
        });
    }

    /**
     * @param  list<array{type: InvoiceItemType, description: string, amount: string}>  $items
     * @return list<array{type: InvoiceItemType, description: string, amount: Money}>
     *
     * @throws ValidationException
     */
    private function validatedLines(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }

        $lines = [];
        foreach ($items as $item) {
            if (! in_array($item['type'], self::ALLOWED, true)) {
                throw ValidationException::withMessages(['items' => 'A one-off invoice can carry installation, reconnection or other fees only.']);
            }

            $description = trim($item['description']);
            if ($description === '' || mb_strlen($description) > 255) {
                throw ValidationException::withMessages(['items' => 'Each item needs a description of 255 characters or fewer.']);
            }

            try {
                $amount = Money::fromPesos($item['amount']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['items' => 'Enter each amount in pesos, for example 500.00.']);
            }

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['items' => 'Each amount must be greater than zero.']);
            }

            $lines[] = ['type' => $item['type'], 'description' => $description, 'amount' => $amount];
        }

        return $lines;
    }

    /** @throws ValidationException */
    private function validatedDueDate(?string $dueDate, CarbonImmutable $today): CarbonImmutable
    {
        if ($dueDate === null || $dueDate === '') {
            return $today;
        }

        // Check the shape and the calendar first, so "2026-02-30" is refused, not rolled over to March.
        $valid = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dueDate, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        $due = $valid ? CarbonImmutable::parse($dueDate)->startOfDay() : null;

        if ($due === null || $due->lessThan($today)) {
            throw ValidationException::withMessages(['due_date' => 'Enter a due date of today or later.']);
        }

        return $due;
    }
}
