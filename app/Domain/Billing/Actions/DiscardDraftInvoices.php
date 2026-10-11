<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Security\Reason;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Throws away the DRAFT invoices of a cycle so the month can be generated again
 * (FR-BIL-004). Only drafts: they have no number and no ledger entry, so
 * nothing financial is lost. A FINALIZED cycle is refused here and, for its
 * numbered invoices, by the database triggers as well (INV-05). The cycle row
 * itself stays; it is only emptied.
 */
final class DiscardDraftInvoices
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $billingCycleId, string $reason): BillingCycle
    {
        Gate::forUser($actor)->authorize('billing.generate');

        $reason = Reason::require($reason);

        return DB::transaction(function () use ($actor, $billingCycleId, $reason): BillingCycle {
            $cycle = BillingCycle::query()->whereKey($billingCycleId)->lockForUpdate()->firstOrFail();

            if ($cycle->status === BillingCycleStatus::Finalized) {
                throw ValidationException::withMessages([
                    'cycle' => 'A finalized billing cycle cannot be discarded. Use an adjustment or a void.',
                ]);
            }

            $draftIds = Invoice::query()
                ->where('billing_cycle_id', $cycle->id)
                ->where('status', InvoiceStatus::Draft->value)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $total = Invoice::query()->whereIn('id', $draftIds)->sum('total_centavos');

            InvoiceItem::query()->whereIn('invoice_id', $draftIds)->delete();
            Invoice::query()->whereIn('id', $draftIds)->delete();

            $cycle->forceFill(['invoice_count' => 0, 'total_centavos' => Money::zero()])->save();

            $this->audit->record(AuditAction::BillingDraftsDiscarded, $actor, 'billing_cycle', $cycle->id, $reason,
                oldValues: ['invoice_count' => count($draftIds), 'total_centavos' => (int) $total],
                newValues: ['invoice_count' => 0, 'total_centavos' => 0],
            );

            return $cycle;
        });
    }
}
