<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domain\Billing\CycleSummaryQuery;
use App\Models\BillingCycle;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Current Billing (FR-BIL-014): the billing cycles, newest first, and for the
 * chosen one the invoices by status, how many are overdue and what is still
 * owed. Read-only. Every figure is computed from invoices, adjustments and
 * allocations; nothing here is a stored total except the cycle's own count and
 * amount, which Generate and Finalize wrote.
 */
#[Title('Current Billing')]
class CurrentBilling extends Component
{
    use AuthorizesRequests;

    private const CYCLES_SHOWN = 12;

    /** The cycle id chosen in the list; anything unknown falls back to the latest. */
    #[Url]
    public string $cycle = '';

    public function boot(): void
    {
        $this->authorize('billing.view');
    }

    public function updated(): void
    {
        $this->authorize('billing.view');
    }

    public function render(): mixed
    {
        $this->authorize('billing.view');

        $cycles = BillingCycle::query()->orderByDesc('period_month')->limit(self::CYCLES_SHOWN)->get();
        $selected = ctype_digit($this->cycle) ? $cycles->firstWhere('id', (int) $this->cycle) : null;
        $selected ??= $cycles->first();

        $summary = app(CycleSummaryQuery::class);

        return view('livewire.billing.current-billing', [
            'cycles' => $cycles,
            'selected' => $selected,
            'statusCounts' => $selected !== null ? $summary->statusCounts($selected->id) : [],
            'overdue' => $selected !== null ? $summary->overdueCount($selected->id) : 0,
            'outstanding' => $selected !== null ? $summary->outstanding($selected->id) : null,
            'canGenerate' => auth()->user()?->hasPermission('billing.generate') === true,
        ]);
    }
}
