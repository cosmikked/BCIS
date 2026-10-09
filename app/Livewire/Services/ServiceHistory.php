<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\ServiceHistoryQuery;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Service History (FR-SUB-008, INV-21): the full sequence of service events,
 * newest first. Read-only; there is no method here that writes, and the table
 * is append-only in the database as well. Filters come from the URL and are
 * converted to typed values (or ignored) before use.
 */
#[Title('Service History')]
class ServiceHistory extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 25;

    /** One service account (the link from the Service Accounts screen). */
    #[Url]
    public string $account = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function boot(): void
    {
        $this->authorize('subscriber.view');
    }

    public function mount(): void
    {
        $this->authorize('subscriber.view');
    }

    public function updated(): void
    {
        $this->authorize('subscriber.view');

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('subscriber.view');

        $this->reset(['account', 'type', 'from', 'to']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $this->authorize('subscriber.view');
        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);

        $accountId = ctype_digit($this->account) ? (int) $this->account : null;

        return view('livewire.services.service-history', [
            'events' => app(ServiceHistoryQuery::class)
                ->build($actor, serviceAccountId: $accountId, type: ServiceEventType::tryFrom($this->type), from: $this->from, to: $this->to)
                ->paginate(self::PER_PAGE),
            'filteredAccount' => $accountId !== null ? ServiceAccount::query()->with('subscriber')->find($accountId) : null,
            'types' => ServiceEventType::cases(),
        ]);
    }
}
