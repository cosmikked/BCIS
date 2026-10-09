<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\AssignCollector;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\AuditLog;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\CollectorAssignment;
use App\Models\ServiceEvent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-011, FR-COL-001, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->account = SubscriberData::account($this->admin);
    $this->rico = Collector::factory()->create(['full_name' => 'Rico']);
    $this->mila = Collector::factory()->create(['full_name' => 'Mila']);
});

afterEach(fn () => Carbon::setTestNow());

it('assigns a first collector: open row, current collector, event and audit', function (): void {
    $assignment = app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $this->rico->id);

    expect($assignment->effective_from->toDateString())->toBe('2026-10-09')
        ->and($assignment->effective_to)->toBeNull()
        ->and($assignment->assigned_by)->toBe($this->admin->id)
        ->and($this->account->refresh()->assigned_collector_id)->toBe($this->rico->id);

    $event = ServiceEvent::query()->where('event_type', ServiceEventType::CollectorChanged->value)->sole();
    expect($event->reference_id)->toBe($assignment->id)->and($event->from_status)->toBe(ServiceStatus::Pending);

    $audit = AuditLog::query()->where('action', 'collector.assigned')->sole();
    expect($audit->old_values['collector_id'])->toBeNull()->and($audit->new_values['collector_id'])->toBe($this->rico->id);
});

it('keeps the earlier assignment readable by date when the collector changes (FR-SUB-011)', function (): void {
    app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $this->rico->id, '2026-09-01');
    app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $this->mila->id, '2026-10-05');

    $rows = CollectorAssignment::query()->where('service_account_id', $this->account->id)->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->collector_id)->toBe($this->rico->id)
        ->and($rows[0]->effective_from->toDateString())->toBe('2026-09-01')
        ->and($rows[0]->effective_to?->toDateString())->toBe('2026-10-04')
        ->and($rows[1]->collector_id)->toBe($this->mila->id)
        ->and($rows[1]->effective_to)->toBeNull();

    // Who was the collector on a given day? The history answers it.
    $on = fn (string $day) => CollectorAssignment::query()
        ->where('service_account_id', $this->account->id)
        ->whereDate('effective_from', '<=', $day)
        ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
        ->value('collector_id');

    expect($on('2026-09-20'))->toBe($this->rico->id)
        ->and($on('2026-10-04'))->toBe($this->rico->id)
        ->and($on('2026-10-05'))->toBe($this->mila->id)
        ->and($this->account->refresh()->assigned_collector_id)->toBe($this->mila->id);
});

it('refuses a pointless or inconsistent change', function (): void {
    app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $this->rico->id, '2026-10-05');
    $assign = fn (int $collectorId, ?string $from) => fn () => app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $collectorId, $from);

    expect($assign($this->rico->id, null))->toThrow(ValidationException::class)       // same collector
        ->and($assign($this->mila->id, '2026-10-05'))->toThrow(ValidationException::class)  // not after the current start
        ->and($assign($this->mila->id, '2026-10-01'))->toThrow(ValidationException::class)  // before it
        ->and($assign($this->mila->id, '2026-10-10'))->toThrow(ValidationException::class)  // future
        ->and($assign($this->mila->id, '10/08/2026'))->toThrow(ValidationException::class); // not a date

    expect(CollectorAssignment::query()->count())->toBe(1)
        ->and(CollectorAssignment::query()->sole()->effective_to)->toBeNull();
});

it('refuses an inactive collector and a terminated account', function (): void {
    $retired = Collector::factory()->create(['is_active' => false]);

    expect(fn () => app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $retired->id))
        ->toThrow(ValidationException::class);

    app(ChangeServiceStatus::class)->handle($this->admin, $this->account->id, ServiceStatus::Terminated, 'Cancelled');

    expect(fn () => app(AssignCollector::class)->forServiceAccount($this->admin, $this->account->id, $this->rico->id))
        ->toThrow(ValidationException::class);
});

it('assigns an area to a collector the same way', function (): void {
    $area = CollectionArea::factory()->create();

    app(AssignCollector::class)->forArea($this->admin, $area->id, $this->rico->id, '2026-09-01');
    app(AssignCollector::class)->forArea($this->admin, $area->id, $this->mila->id, '2026-10-01');

    $rows = CollectorAssignment::query()->where('collection_area_id', $area->id)->orderBy('effective_from')->get();
    expect($rows->pluck('collector_id')->all())->toBe([$this->rico->id, $this->mila->id])
        ->and($rows[0]->effective_to?->toDateString())->toBe('2026-09-30')
        ->and(AuditLog::query()->where('action', 'collector.assigned')->where('entity_type', 'collection_area')->count())->toBe(2);
});

it('allows the Collection Supervisor and refuses roles without collection.manage (AT-10)', function (): void {
    $supervisor = Users::make('sup', RoleCode::CollectionSupervisor);
    app(AssignCollector::class)->forServiceAccount($supervisor, $this->account->id, $this->rico->id);
    expect($this->account->refresh()->assigned_collector_id)->toBe($this->rico->id);

    foreach ([RoleCode::Cashier, RoleCode::Technician, RoleCode::Viewer, RoleCode::Auditor] as $i => $role) {
        $user = Users::make("other{$i}", $role);

        expect(fn () => app(AssignCollector::class)->forServiceAccount($user, $this->account->id, $this->mila->id, '2026-10-09'))
            ->toThrow(AuthorizationException::class);
    }

    expect($this->account->refresh()->assigned_collector_id)->toBe($this->rico->id);
});
