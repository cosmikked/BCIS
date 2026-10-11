<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\AdjustmentRules;
use App\Domain\Billing\DocumentNumberGenerator;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Models\Adjustment;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Asks for a debit, credit, write-off or void on a finalized invoice (FR-BIL-009,
 * FR-BIL-013). This is only the REQUEST: it is numbered and saved as PENDING and
 * changes no balance and no ledger. A different user approves it later
 * (ApproveAdjustment), and only that posts the counter-entry.
 *
 * The invoice row is locked, so two requests for the same invoice are checked one
 * after the other against the same balance.
 */
final class CreateAdjustment
{
    public function __construct(
        private readonly AdjustmentRules $rules,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $amount  pesos text; ignored for a void, which always cancels the full balance
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $invoiceId, AdjustmentKind $kind, ?string $amount, string $reason): Adjustment
    {
        Gate::forUser($actor)->authorize($kind->permission()->value);

        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $invoiceId, $kind, $amount, $reason): Adjustment {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            $money = $this->rules->resolveAmount($invoice, $kind, $amount);
            $this->rules->assertApplicable($invoice, $kind, $money);

            if ($kind === AdjustmentKind::Void && $this->hasPendingVoid($invoice)) {
                throw ValidationException::withMessages(['invoice' => 'A void request for this invoice is already waiting for approval.']);
            }

            $adjustment = Adjustment::query()->create([
                'adjustment_no' => $this->numbers->nextAdjustmentNo(),
                'subscriber_id' => $invoice->subscriber_id,
                'service_account_id' => $invoice->service_account_id,
                'invoice_id' => $invoice->id,
                'kind' => $kind,
                'amount_centavos' => $money,
                'reason' => $reason,
                'status' => AdjustmentStatus::Pending,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            $this->audit->record(AuditAction::AdjustmentRequested, $actor, 'adjustment', $adjustment->id, $reason,
                newValues: [
                    'adjustment_no' => $adjustment->adjustment_no,
                    'invoice_no' => $invoice->invoice_no,
                    'kind' => $kind->value,
                    'amount_centavos' => $money->centavos,
                ],
            );

            return $adjustment;
        });
    }

    private function hasPendingVoid(Invoice $invoice): bool
    {
        return Adjustment::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', AdjustmentKind::Void->value)
            ->where('status', AdjustmentStatus::Pending->value)
            ->exists();
    }
}
