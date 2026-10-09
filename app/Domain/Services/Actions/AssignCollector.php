<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\CollectorAssignment;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Dated collector assignment (FR-SUB-011, FR-COL-001). Changing a collector
 * CLOSES the open assignment (effective_to = the day before the new one starts)
 * and adds a new row. Nothing earlier is rewritten, so a batch or report that
 * ran under the old collector still shows the old collector.
 *
 * A service account also keeps the current collector in
 * service_accounts.assigned_collector_id for quick lookup; this Action updates
 * both together in one transaction. The target row is locked first, so two
 * people reassigning the same account cannot both win.
 *
 * Start dates may be today or earlier, but must be after the current
 * assignment's start. A future date is refused because the current-collector
 * column could not honestly show it (a flagged decision).
 */
final class AssignCollector
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function forServiceAccount(User $actor, int $serviceAccountId, int $collectorId, ?string $effectiveFrom = null): CollectorAssignment
    {
        Gate::forUser($actor)->authorize('collection.manage');
        $date = $this->date($effectiveFrom);

        return DB::transaction(function () use ($actor, $serviceAccountId, $collectorId, $date): CollectorAssignment {
            $account = ServiceAccount::query()->whereKey($serviceAccountId)->lockForUpdate()->firstOrFail();

            if ($account->status === ServiceStatus::Terminated) {
                throw ValidationException::withMessages(['collector_id' => 'A terminated service account cannot be assigned.']);
            }

            $collector = $this->activeCollector($collectorId);
            $previousCollectorId = $account->assigned_collector_id;

            $assignment = $this->replaceOpenAssignment('service_account_id', $account->id, $collector, $date, $actor);

            $account->forceFill(['assigned_collector_id' => $collector->id])->save();

            ServiceEvent::query()->create([
                'service_account_id' => $account->id,
                'event_type' => ServiceEventType::CollectorChanged,
                'from_status' => $account->status,
                'to_status' => $account->status,
                'actor_user_id' => $actor->id,
                'reference_type' => 'collector_assignment',
                'reference_id' => $assignment->id,
                'notes' => "Collector: {$collector->full_name}, from {$date->toDateString()}",
            ]);

            $this->audit->record(AuditAction::CollectorAssigned, $actor, 'service_account', $account->id,
                oldValues: ['collector_id' => $previousCollectorId],
                newValues: ['collector_id' => $collector->id, 'effective_from' => $date->toDateString()],
            );

            return $assignment;
        });
    }

    /** @throws ValidationException */
    public function forArea(User $actor, int $areaId, int $collectorId, ?string $effectiveFrom = null): CollectorAssignment
    {
        Gate::forUser($actor)->authorize('collection.manage');
        $date = $this->date($effectiveFrom);

        return DB::transaction(function () use ($actor, $areaId, $collectorId, $date): CollectorAssignment {
            $area = CollectionArea::query()->whereKey($areaId)->lockForUpdate()->firstOrFail();
            $collector = $this->activeCollector($collectorId);

            $previousCollectorId = CollectorAssignment::query()
                ->where('collection_area_id', $area->id)->whereNull('effective_to')->value('collector_id');

            $assignment = $this->replaceOpenAssignment('collection_area_id', $area->id, $collector, $date, $actor);

            $this->audit->record(AuditAction::CollectorAssigned, $actor, 'collection_area', $area->id,
                oldValues: ['collector_id' => $previousCollectorId],
                newValues: ['collector_id' => $collector->id, 'effective_from' => $date->toDateString()],
            );

            return $assignment;
        });
    }

    private function replaceOpenAssignment(string $column, int $targetId, Collector $collector, Carbon $date, User $actor): CollectorAssignment
    {
        $open = CollectorAssignment::query()->where($column, $targetId)->whereNull('effective_to')->lockForUpdate()->first();

        if ($open !== null) {
            if ($open->collector_id === $collector->id) {
                throw ValidationException::withMessages(['collector_id' => "{$collector->full_name} is already the assigned collector."]);
            }

            if ($date->toDateString() <= $open->effective_from->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The start date must be after the current assignment started on '.$open->effective_from->toDateString().'.',
                ]);
            }

            $open->forceFill(['effective_to' => $date->copy()->subDay()->toDateString()])->save();
        }

        return CollectorAssignment::query()->create([
            'collector_id' => $collector->id,
            $column => $targetId,
            'effective_from' => $date->toDateString(),
            'assigned_by' => $actor->id,
        ]);
    }

    private function activeCollector(int $collectorId): Collector
    {
        $collector = Collector::query()->whereKey($collectorId)->lockForUpdate()->first();

        if ($collector === null || ! $collector->is_active) {
            throw ValidationException::withMessages(['collector_id' => 'Choose an active collector.']);
        }

        return $collector;
    }

    private function date(?string $text): Carbon
    {
        // "Today" is the Asia/Manila business date (INV-22).
        $today = Carbon::today();

        if ($text === null || trim($text) === '') {
            return $today;
        }

        $date = Carbon::hasFormat(trim($text), 'Y-m-d') ? Carbon::createFromFormat('Y-m-d', trim($text)) : false;

        if (! $date instanceof Carbon) {
            throw ValidationException::withMessages(['effective_from' => 'Enter the start date as YYYY-MM-DD.']);
        }

        $date = $date->startOfDay();

        if ($date->greaterThan($today)) {
            throw ValidationException::withMessages(['effective_from' => 'The start date cannot be in the future.']);
        }

        return $date;
    }
}
