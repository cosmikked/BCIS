<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\AdjustmentRules;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\InvoiceStatusResolver;
use App\Domain\Billing\LedgerPoster;
use App\Domain\Billing\LedgerPosting;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\Adjustment;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Approves a pending adjustment or void (FR-BIL-009, FR-BIL-013, INV-02, INV-06).
 * Maker-checker (PRD 19 #8): the approver must be a different user from the
 * requester. The Action says so in plain words and the database CHECK
 * `approved_by <> requested_by` makes it impossible to get around.
 *
 * In ONE transaction, with the adjustment and then the invoice locked:
 *   1. the request is checked again against the invoice as it is now, because a
 *      payment or another adjustment may have changed the balance since;
 *   2. the request becomes APPROVED;
 *   3. the ledger counter-entry is posted (debit for a DEBIT, credit otherwise);
 *   4. the invoice status is recomputed from its new balance; a void sets VOID;
 *   5. the audit record is written.
 */
final class ApproveAdjustment
{
    public function __construct(
        private readonly AdjustmentRules $rules,
        private readonly LedgerPoster $ledger,
        private readonly InvoiceBalanceCalculator $balances,
        private readonly InvoiceStatusResolver $statuses,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $adjustmentId, ?string $note = null): Adjustment
    {
        // The kind decides which permission is needed, so read it before locking.
        $kind = Adjustment::query()->whereKey($adjustmentId)->firstOrFail()->kind;
        Gate::forUser($actor)->authorize($kind->permission()->value);

        return DB::transaction(function () use ($actor, $adjustmentId, $note): Adjustment {
            $adjustment = Adjustment::query()->whereKey($adjustmentId)->lockForUpdate()->firstOrFail();

            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages(['adjustment' => 'This request has already been decided.']);
            }

            if ($adjustment->requested_by === $actor->id) {
                throw ValidationException::withMessages(['adjustment' => 'You cannot approve your own request. Another authorized user must approve it.']);
            }

            $invoice = Invoice::query()->whereKey($adjustment->invoice_id)->lockForUpdate()->firstOrFail();

            $this->rules->assertApplicable($invoice, $adjustment->kind, $adjustment->amount_centavos);

            $adjustment->forceFill([
                'status' => AdjustmentStatus::Approved,
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->postCounterEntry($actor, $adjustment, $invoice);

            if ($adjustment->kind === AdjustmentKind::Void) {
                $statusBefore = $invoice->status;
                $invoice->forceFill([
                    'status' => InvoiceStatus::Void,
                    'voided_at' => now(),
                    'void_reason' => $adjustment->reason,
                ])->save();

                $this->audit->record(AuditAction::InvoiceVoided, $actor, 'invoice', $invoice->id, $adjustment->reason,
                    oldValues: ['status' => $statusBefore->value],
                    newValues: [
                        'status' => InvoiceStatus::Void->value,
                        'invoice_no' => $invoice->invoice_no,
                        'adjustment_no' => $adjustment->adjustment_no,
                        'voided_centavos' => $adjustment->amount_centavos->centavos,
                    ],
                );
            } else {
                $status = $this->statuses->statusFor($invoice->status, $this->balances->figuresFor($invoice));
                if ($status !== $invoice->status) {
                    $invoice->forceFill(['status' => $status])->save();
                }
            }

            $this->audit->record(AuditAction::AdjustmentApproved, $actor, 'adjustment', $adjustment->id, $note,
                oldValues: ['status' => AdjustmentStatus::Pending->value],
                newValues: [
                    'status' => AdjustmentStatus::Approved->value,
                    'adjustment_no' => $adjustment->adjustment_no,
                    'invoice_no' => $invoice->invoice_no,
                    'kind' => $adjustment->kind->value,
                    'amount_centavos' => $adjustment->amount_centavos->centavos,
                    'requested_by' => $adjustment->requested_by,
                ],
            );

            return $adjustment;
        });
    }

    private function postCounterEntry(User $actor, Adjustment $adjustment, Invoice $invoice): void
    {
        $amount = $adjustment->amount_centavos;
        $debit = $adjustment->kind->increasesBalance();

        $this->ledger->post(new LedgerPosting(
            type: LedgerEntryType::Adjustment,
            subscriberId: $invoice->subscriber_id,
            serviceAccountId: $invoice->service_account_id,
            // The correction is dated the day it is approved, not back-dated into
            // a period that may already have been reported.
            entryDate: CarbonImmutable::today(),
            debit: $debit ? $amount : Money::zero(),
            credit: $debit ? Money::zero() : $amount,
            description: Str::limit($adjustment->kind->label().' '.$adjustment->adjustment_no.' on '.$invoice->invoice_no.': '.$adjustment->reason, 250),
            referenceType: 'adjustment',
            referenceId: $adjustment->id,
            referenceNo: $adjustment->adjustment_no,
            actor: $actor,
        ));
    }
}
