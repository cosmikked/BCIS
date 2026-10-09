<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Domain\Subscribers\Search\GlobalSearch;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The query behind the All Subscribers screen (FR-SUB-003, NFR-02). It returns
 * a Builder; the component paginates it on the server, so 20,000 subscribers
 * never load at once. Ordered by name then id so page boundaries are stable.
 */
final class SubscriberListQuery
{
    public function __construct(private readonly GlobalSearch $search) {}

    /** @return Builder<Subscriber> */
    public function build(User $actor, SubscriberListFilters $filters): Builder
    {
        Gate::forUser($actor)->authorize('subscriber.view');

        $query = Subscriber::query()
            ->with([
                'collectionArea',
                'contacts' => fn ($contacts) => $contacts->where('is_primary', true),
            ])
            ->withCount('serviceAccounts');

        $this->search->applyTo($query, $filters->search);

        if ($filters->status !== null) {
            $query->where('subscribers.status', $filters->status->value);
        }

        if ($filters->areaId !== null) {
            $query->where('subscribers.collection_area_id', $filters->areaId);
        }

        if ($filters->collectorId !== null) {
            $query->whereHas('serviceAccounts', fn ($accounts) => $accounts->where('assigned_collector_id', $filters->collectorId));
        }

        if ($filters->serviceTypeId !== null) {
            $query->whereHas('serviceAccounts.plan', fn ($plans) => $plans->where('service_type_id', $filters->serviceTypeId));
        }

        return $query->orderBy('subscribers.full_name')->orderBy('subscribers.id');
    }
}
