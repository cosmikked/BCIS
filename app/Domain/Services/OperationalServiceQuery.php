<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\ContactNormalizer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The Technician's list of service accounts (FR-SUB-013). The restriction is
 * enforced HERE, in the query: it names the few columns the job needs and joins
 * only the tables that hold them. It never selects a rate, due day, billing
 * date, contact, note or anything from billing, payments or the ledger, so the
 * data is not loaded, not only hidden in the view. The result is mapped to
 * OperationalServiceRow, the only shape the screen sees.
 *
 * Subscriber notes and the reasons typed into service history are left out on
 * purpose: they can mention money ("unpaid invoices"). A separate field for job
 * instructions arrives with the suspension and reconnection work.
 */
final class OperationalServiceQuery
{
    private const PER_PAGE = 20;

    /** The event types that mark a change of status, for the "since" date. */
    private const STATUS_EVENTS = [
        ServiceEventType::Created,
        ServiceEventType::Activated,
        ServiceEventType::Suspended,
        ServiceEventType::Reconnected,
        ServiceEventType::Terminated,
    ];

    /** @return LengthAwarePaginator<int, OperationalServiceRow> */
    public function paginate(User $actor, string $search = '', ?ServiceStatus $status = null): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('service.view');

        return $this->query($search, $status)->paginate(self::PER_PAGE)->through(
            fn (object $row): OperationalServiceRow => new OperationalServiceRow(
                id: (int) $row->id,
                accountNo: (string) $row->account_no,
                subscriberName: (string) $row->subscriber_name,
                serviceAddress: implode(', ', array_filter([$row->line1, $row->barangay, $row->city])),
                planName: (string) $row->plan_name,
                serviceType: (string) $row->service_type,
                status: ServiceStatus::from((string) $row->status),
                statusDate: $row->status_changed_at !== null ? Carbon::parse($row->status_changed_at) : null,
            ),
        );
    }

    /**
     * The whitelisted query. Public so a test can read its SQL and prove that no
     * financial column is selected or joined.
     */
    public function query(string $search = '', ?ServiceStatus $status = null): Builder
    {
        $query = DB::table('service_accounts as sa')
            ->join('subscribers as s', 's.id', '=', 'sa.subscriber_id')
            ->join('subscriber_addresses as a', 'a.id', '=', 'sa.service_address_id')
            ->join('service_plans as p', 'p.id', '=', 'sa.plan_id')
            ->join('service_types as t', 't.id', '=', 'p.service_type_id')
            ->select([
                'sa.id',
                'sa.account_no',
                'sa.status',
                's.full_name as subscriber_name',
                'a.line1',
                'a.barangay',
                'a.city',
                'p.name as plan_name',
                't.name as service_type',
            ])
            ->selectSub(
                fn ($sub) => $sub->from('service_events as e')
                    ->selectRaw('max(e.occurred_at)')
                    ->whereColumn('e.service_account_id', 'sa.id')
                    ->whereIn('e.event_type', array_map(fn (ServiceEventType $t) => $t->value, self::STATUS_EVENTS)),
                'status_changed_at',
            );

        if ($status !== null) {
            $query->where('sa.status', $status->value);
        } else {
            // Ended services are not jobs; they appear only when asked for.
            $query->where('sa.status', '!=', ServiceStatus::Terminated->value);
        }

        $term = trim((string) preg_replace('/\s+/', ' ', $search));
        if (mb_strlen($term) >= 2) {
            $like = '%'.ContactNormalizer::escapeLike($term).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('sa.account_no', 'ilike', $like)
                    ->orWhere('s.full_name', 'ilike', $like)
                    ->orWhereRaw("(a.line1 || ' ' || coalesce(a.barangay, '') || ' ' || a.city) ilike ?", [$like]);
            });
        }

        // Jobs first: installations waiting, then disconnected, then in service.
        return $query
            ->orderByRaw("case sa.status when 'PENDING' then 0 when 'SUSPENDED' then 1 when 'ACTIVE' then 2 else 3 end")
            ->orderBy('a.barangay')
            ->orderBy('s.full_name')
            ->orderBy('sa.id');
    }
}
