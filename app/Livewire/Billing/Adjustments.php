<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\RejectAdjustment;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Models\Adjustment;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Adjustment and void requests waiting for a decision (FR-BIL-009, FR-BIL-013,
 * PRD 19 #8). The person who asked cannot decide their own request: the Actions
 * refuse it and the database CHECK backs that up, and this screen simply does
 * not offer the buttons. Each kind needs its own permission to be seen and
 * decided (invoice.adjust, invoice.void). Thin: ApproveAdjustment and
 * RejectAdjustment hold the rules, including re-checking the invoice balance at
 * the moment of approval.
 */
#[Title('Adjustments')]
class Adjustments extends Component
{
    use AuthorizesRequests;

    private const RECENT = 15;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public string $message = '';

    public string $note = '';

    public function boot(): void
    {
        $this->authorize('invoice.adjust');
    }

    public function select(int $adjustmentId): void
    {
        $this->authorize('invoice.adjust');

        $adjustment = Adjustment::query()->findOrFail($adjustmentId);
        $this->authorize($adjustment->kind->permission()->value);

        $this->selectedId = $adjustment->id;
        $this->reset(['note', 'message']);
        $this->resetErrorBag();
    }

    public function approve(ApproveAdjustment $approve): void
    {
        $this->authorize('invoice.adjust');
        abort_if($this->selectedId === null, 422, 'Choose a request first.');

        $adjustment = $approve->handle($this->actor(), $this->selectedId, $this->note);

        $this->message = "{$adjustment->adjustment_no} approved and posted to the ledger.";
        $this->reset(['selectedId', 'note']);
        $this->resetErrorBag();
    }

    public function reject(RejectAdjustment $reject): void
    {
        $this->authorize('invoice.adjust');
        abort_if($this->selectedId === null, 422, 'Choose a request first.');

        $adjustment = $reject->handle($this->actor(), $this->selectedId, $this->note);

        $this->message = "{$adjustment->adjustment_no} rejected. Nothing was posted.";
        $this->reset(['selectedId', 'note']);
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('invoice.adjust');
        $actor = $this->actor();

        $kinds = array_map(
            fn (AdjustmentKind $k) => $k->value,
            array_filter(AdjustmentKind::cases(), fn (AdjustmentKind $k) => $actor->hasPermission($k->permission()->value)),
        );

        $with = ['invoice.subscriber', 'requester', 'approver'];

        $pending = Adjustment::query()->with($with)
            ->where('status', AdjustmentStatus::Pending->value)
            ->whereIn('kind', $kinds)
            ->orderBy('id')->get();

        $recent = Adjustment::query()->with($with)
            ->where('status', '<>', AdjustmentStatus::Pending->value)
            ->whereIn('kind', $kinds)
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(self::RECENT)->get();

        $selected = $this->selectedId !== null ? $pending->firstWhere('id', $this->selectedId) : null;

        return view('livewire.billing.adjustments', [
            'pending' => $pending,
            'recent' => $recent,
            'selected' => $selected,
            // Shown so the approver sees the balance as it is NOW, not as it was when asked.
            'currentBalance' => $selected !== null ? app(InvoiceBalanceCalculator::class)->balance($selected->invoice) : null,
            'me' => $actor->id,
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
