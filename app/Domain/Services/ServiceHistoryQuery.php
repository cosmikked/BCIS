<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Enums\ServiceEventType;
use App\Models\ServiceEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Read-only view of service_events (FR-SUB-008, INV-21): newest first, paged by
 * the component. There is no write path here; the rows are append-only.
 */
final class ServiceHistoryQuery
{
    /**
     * @param  string  $from  YYYY-MM-DD or empty
     * @param  string  $to  YYYY-MM-DD or empty
     * @return Builder<ServiceEvent>
     */
    public function build(
        User $actor,
        ?int $serviceAccountId = null,
        ?int $subscriberId = null,
        ?ServiceEventType $type = null,
        string $from = '',
        string $to = '',
    ): Builder {
        Gate::forUser($actor)->authorize('subscriber.view');

        $query = ServiceEvent::query()->with(['serviceAccount.subscriber', 'actor']);

        if ($serviceAccountId !== null) {
            $query->where('service_events.service_account_id', $serviceAccountId);
        }

        if ($subscriberId !== null) {
            $query->whereHas('serviceAccount', fn ($accounts) => $accounts->where('subscriber_id', $subscriberId));
        }

        if ($type !== null) {
            $query->where('service_events.event_type', $type->value);
        }

        if (($start = $this->day($from)) !== null) {
            $query->where('service_events.occurred_at', '>=', $start->startOfDay());
        }

        if (($end = $this->day($to)) !== null) {
            $query->where('service_events.occurred_at', '<=', $end->endOfDay());
        }

        return $query->orderByDesc('service_events.occurred_at')->orderByDesc('service_events.id');
    }

    /** A business date in Asia/Manila, or null when empty or not a valid date. */
    private function day(string $value): ?Carbon
    {
        if (trim($value) === '' || ! Carbon::hasFormat(trim($value), 'Y-m-d')) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value), 'Asia/Manila') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
