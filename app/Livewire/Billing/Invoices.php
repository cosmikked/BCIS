<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\InvoiceFilters;
use App\Domain\Billing\InvoiceListQuery;
use App\Domain\Billing\InvoiceRows;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invoices (FR-BIL-014): search and filter the numbered invoices, with the
 * balance and a derived OVERDUE badge for each. Thin: InvoiceListQuery holds
 * the filters, InvoiceBalanceCalculator the balances (two queries for the whole
 * page, not one per row) and InvoiceStatusResolver the overdue rule.
 */
#[Title('Invoices')]
class Invoices extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 20;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** Billing month as Y-m. */
    #[Url]
    public string $period = '';

    /** An InvoiceStatus value, or OVERDUE (derived). */
    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $subscriber = '';

    /** Limits the list to one billing cycle (the link from Current Billing). */
    #[Url]
    public string $cycle = '';

    public function boot(): void
    {
        $this->authorize('billing.view');
    }

    public function updated(): void
    {
        $this->authorize('billing.view');

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('billing.view');

        $this->reset(['search', 'period', 'status', 'type', 'subscriber', 'cycle']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $this->authorize('billing.view');
        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);

        $filters = new InvoiceFilters(
            search: $this->search,
            period: preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) === 1 ? $this->period.'-01' : null,
            status: $this->status === 'OVERDUE' ? null : InvoiceStatus::tryFrom($this->status),
            overdueOnly: $this->status === 'OVERDUE',
            type: InvoiceType::tryFrom($this->type),
            subscriberId: ctype_digit($this->subscriber) ? (int) $this->subscriber : null,
            billingCycleId: ctype_digit($this->cycle) ? (int) $this->cycle : null,
        );

        $invoices = app(InvoiceListQuery::class)->build($actor, $filters)->paginate(self::PER_PAGE);

        return view('livewire.billing.invoices', [
            'invoices' => $invoices,
            'rows' => app(InvoiceRows::class)->for($invoices->getCollection()),
            'statuses' => array_filter(InvoiceStatus::cases(), fn (InvoiceStatus $s) => $s !== InvoiceStatus::Draft),
            'types' => InvoiceType::cases(),
            'filteredSubscriber' => $filters->subscriberId !== null ? Subscriber::query()->find($filters->subscriberId) : null,
        ]);
    }
}
