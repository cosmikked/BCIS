<?php

declare(strict_types=1);

namespace App\Livewire\Subscribers;

use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Domain\Subscribers\SubscriberListFilters;
use App\Domain\Subscribers\SubscriberListQuery;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * All Subscribers (FR-SUB-003, NFR-02). Search and filters are bound to the URL,
 * so a filtered list can be bookmarked or shared. The query, including the
 * search, lives in SubscriberListQuery; this component only passes the filters
 * in and pages the result on the server. Filter values are untrusted input and
 * are converted to typed values (or ignored) before use.
 */
#[Title('All Subscribers')]
class SubscriberList extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $area = '';

    #[Url]
    public string $collector = '';

    #[Url]
    public string $type = '';

    public function boot(): void
    {
        $this->authorize('subscriber.view');
    }

    public function mount(): void
    {
        $this->authorize('subscriber.view');
    }

    // Any filter or search change goes back to the first page.
    public function updated(): void
    {
        $this->authorize('subscriber.view');

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('subscriber.view');

        $this->reset(['search', 'status', 'area', 'collector', 'type']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 401);
        $this->authorize('subscriber.view');

        $filters = new SubscriberListFilters(
            search: $this->search,
            status: SubscriberStatus::tryFrom($this->status),
            areaId: ctype_digit($this->area) ? (int) $this->area : null,
            collectorId: ctype_digit($this->collector) ? (int) $this->collector : null,
            serviceTypeId: ctype_digit($this->type) ? (int) $this->type : null,
        );

        return view('livewire.subscribers.subscriber-list', [
            'subscribers' => app(SubscriberListQuery::class)->build($actor, $filters)->paginate(self::PER_PAGE),
            'statuses' => SubscriberStatus::cases(),
            'areas' => CollectionArea::query()->orderBy('name')->get(['id', 'name']),
            'collectors' => Collector::query()->orderBy('full_name')->get(['id', 'full_name']),
            'types' => ServiceType::query()->orderBy('id')->get(['id', 'name']),
            'searchTooShort' => trim($this->search) !== '' && mb_strlen(trim($this->search)) < 2,
            'canManage' => $actor->hasPermission('subscriber.manage'),
        ]);
    }
}
