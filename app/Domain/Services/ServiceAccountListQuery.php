<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Models\ServiceAccount;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The query behind the Service Accounts screen (FR-SUB-007, NFR-02). Paged on
 * the server by the component. The search matches the service account number
 * directly and everything GlobalSearch finds on the owning subscriber (name,
 * contact, address). A service account's area is the area of its service
 * address.
 *
 * This query includes the rate, so it needs subscriber.view. The Technician's
 * operational list is a different query that never selects the rate.
 */
final class ServiceAccountListQuery
{
    public function __construct(private readonly GlobalSearch $search) {}

    /** @return Builder<ServiceAccount> */
    public function build(User $actor, ServiceAccountFilters $filters): Builder
    {
        Gate::forUser($actor)->authorize('subscriber.view');

        $query = ServiceAccount::query()
            ->with(['subscriber', 'plan.serviceType', 'serviceAddress.collectionArea', 'collector']);

        $term = GlobalSearch::clean($filters->search);

        if (GlobalSearch::isSearchable($term)) {
            $subscribers = Subscriber::query()->select('subscribers.id');
            $this->search->applyTo($subscribers, $term);

            $like = '%'.ContactNormalizer::escapeLike($term).'%';
            $query->where(function (Builder $inner) use ($like, $subscribers): void {
                $inner->where('service_accounts.account_no', 'ilike', $like)
                    ->orWhereIn('service_accounts.subscriber_id', $subscribers->toBase());
            });
        }

        if ($filters->status !== null) {
            $query->where('service_accounts.status', $filters->status->value);
        }

        if ($filters->subscriberId !== null) {
            $query->where('service_accounts.subscriber_id', $filters->subscriberId);
        }

        if ($filters->collectorId !== null) {
            $query->where('service_accounts.assigned_collector_id', $filters->collectorId);
        }

        if ($filters->serviceTypeId !== null) {
            $query->whereHas('plan', fn ($plans) => $plans->where('service_type_id', $filters->serviceTypeId));
        }

        if ($filters->areaId !== null) {
            $query->whereHas('serviceAddress', fn ($addresses) => $addresses->where('collection_area_id', $filters->areaId));
        }

        return $query->orderBy('service_accounts.account_no');
    }
}
