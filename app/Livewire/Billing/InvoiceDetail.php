<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\InvoiceStatusResolver;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One invoice (FR-BIL-014, FR-BIL-009): items, how the balance is made up,
 * the adjustment and void requests with their decisions, and a form to ask for
 * a new one. Asking only creates a PENDING request; another user decides it on
 * the Adjustments screen. Thin: CreateAdjustment holds every rule.
 *
 * The ledger lines of this invoice are shown only to a user who may see the
 * subscriber ledger (receivable.view), the same gate as the Ledger tab.
 */
#[Title('Invoice')]
class InvoiceDetail extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $invoiceId;

    #[Locked]
    public string $message = '';

    public string $kind = '';

    public string $amount = '';

    public string $reason = '';

    public function boot(): void
    {
        $this->authorize('billing.view');
    }

    public function mount(int $invoice): void
    {
        $this->authorize('billing.view');

        $this->invoiceId = Invoice::query()->findOrFail($invoice)->id;
    }

    public function requestAdjustment(CreateAdjustment $createAdjustment): void
    {
        $kind = AdjustmentKind::tryFrom($this->kind);
        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => 'Choose what to request.']);
        }

        // Each kind has its own permission: invoice.adjust, or invoice.void for a void.
        $this->authorize($kind->permission()->value);

        $adjustment = $createAdjustment->handle($this->actor(), $this->invoiceId, $kind, $this->amount, $this->reason);

        $this->message = "{$kind->label()} {$adjustment->adjustment_no} requested. It changes nothing until another authorized user approves it.";
        $this->reset(['kind', 'amount', 'reason']);
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('billing.view');
        $actor = $this->actor();

        $invoice = Invoice::query()
            ->with(['subscriber', 'serviceAccount.plan', 'items', 'adjustments.requester', 'adjustments.approver', 'cycle'])
            ->findOrFail($this->invoiceId);

        $figures = app(InvoiceBalanceCalculator::class)->figuresFor($invoice);
        $resolver = app(InvoiceStatusResolver::class);
        $overdue = $resolver->isOverdue($invoice->status, $invoice->due_date, $figures->balance());

        $kinds = array_values(array_filter(
            AdjustmentKind::cases(),
            fn (AdjustmentKind $k) => $actor->hasPermission($k->permission()->value),
        ));

        $canRequest = $invoice->status->isFinalized() && $invoice->status !== InvoiceStatus::Void && $kinds !== [];

        return view('livewire.billing.invoice-detail', [
            'invoice' => $invoice,
            'figures' => $figures,
            'overdue' => $overdue,
            'daysPastDue' => $resolver->daysPastDue($invoice->due_date),
            'adjustments' => $invoice->adjustments->sortByDesc('id')->values(),
            'ledger' => $actor->hasPermission('receivable.view') ? $this->ledgerLines($invoice) : null,
            'kinds' => $kinds,
            'canRequest' => $canRequest,
            'hasPendingVoid' => $invoice->adjustments->contains(fn ($a) => $a->kind === AdjustmentKind::Void && $a->status->value === 'PENDING'),
        ])->title($invoice->invoice_no ?? 'Draft invoice');
    }

    /** @return Collection<int, LedgerEntry> */
    private function ledgerLines(Invoice $invoice): Collection
    {
        $adjustmentIds = $invoice->adjustments->pluck('id')->all();

        return LedgerEntry::query()
            ->where(function ($q) use ($invoice, $adjustmentIds): void {
                $q->where(fn ($i) => $i->where('reference_type', 'invoice')->where('reference_id', $invoice->id))
                    ->orWhere(fn ($a) => $a->where('reference_type', 'adjustment')->whereIn('reference_id', $adjustmentIds));
            })
            ->orderBy('entry_date')->orderBy('id')
            ->get();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
