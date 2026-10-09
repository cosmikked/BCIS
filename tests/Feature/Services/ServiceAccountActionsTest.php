<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Actions\CreateServiceAccount;
use App\Domain\Services\Enums\ServiceEventType;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-002, FR-SUB-007, FR-SUB-008, INV-19, INV-20, INV-21, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = Users::make('admin', RoleCode::Administrator);
});

it('creates a PENDING account that copies the plan rate and writes the first event (FR-SUB-007)', function (): void {
    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(149900)]);
    $subscriber = SubscriberData::subscriber($this->admin, ['billing_due_day' => 12]);

    $account = SubscriberData::account($this->admin, $subscriber, $plan);

    expect($account->account_no)->toMatch('/^SVC-\d{6}$/')
        ->and($account->status)->toBe(ServiceStatus::Pending)
        ->and($account->current_rate_centavos->centavos)->toBe(149900)
        ->and($account->billing_due_day)->toBe(12)   // defaults from the subscriber
        ->and($account->activation_date)->toBeNull()
        ->and($account->created_by)->toBe($this->admin->id);

    $event = ServiceEvent::query()->where('service_account_id', $account->id)->sole();
    expect($event->event_type)->toBe(ServiceEventType::Created)
        ->and($event->from_status)->toBeNull()
        ->and($event->to_status)->toBe(ServiceStatus::Pending)
        ->and($event->actor_user_id)->toBe($this->admin->id);

    expect(AuditLog::query()->where('action', 'service_account.created')->sole()->new_values['current_rate_centavos'])->toBe(149900);
});

it('lets one subscriber own several accounts at different addresses, each with its own number (FR-SUB-002)', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin, ['addresses' => [
        ['address_type' => 'SERVICE', 'line1' => '1 First St', 'city' => 'Malaybalay City'],
        ['address_type' => 'SERVICE', 'line1' => '2 Second St', 'city' => 'Malaybalay City'],
    ]]);
    [$first, $second] = $subscriber->addresses()->orderBy('id')->get()->all();
    $plan = ServicePlan::factory()->create();

    $a = SubscriberData::account($this->admin, $subscriber, $plan, ['service_address_id' => $first->id]);
    $b = SubscriberData::account($this->admin, $subscriber, $plan, ['service_address_id' => $second->id]);

    expect($a->account_no)->not->toBe($b->account_no)
        ->and($a->service_address_id)->not->toBe($b->service_address_id)
        ->and($subscriber->serviceAccounts()->count())->toBe(2);
});

it('treats a Combo as one account on one Combo plan', function (): void {
    $combo = ServicePlan::factory()->combo()->create();

    $account = SubscriberData::account($this->admin, plan: $combo);

    expect($account->subscriber->serviceAccounts()->count())->toBe(1)
        ->and($account->plan->serviceType->code)->toBe('COMBO');
});

it('refuses an address that is not one of the subscriber\'s service addresses', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin);
    $stranger = SubscriberData::subscriber($this->admin);
    $billing = SubscriberData::subscriber($this->admin, ['addresses' => [
        ['address_type' => 'BILLING', 'line1' => '9 Billing St', 'city' => 'Malaybalay City'],
    ]]);
    $plan = ServicePlan::factory()->create();

    expect(fn () => SubscriberData::account($this->admin, $subscriber, $plan, ['service_address_id' => $stranger->addresses()->first()->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => SubscriberData::account($this->admin, $billing, $plan))
        ->toThrow(ValidationException::class);

    expect(ServiceAccount::query()->count())->toBe(0);
});

it('rejects bad input without creating anything', function (): void {
    $subscriber = SubscriberData::subscriber($this->admin);

    expect(fn () => SubscriberData::account($this->admin, $subscriber, null, ['billing_start_date' => 'next week']))->toThrow(ValidationException::class)
        ->and(fn () => SubscriberData::account($this->admin, $subscriber, null, ['billing_due_day' => 40]))->toThrow(ValidationException::class);

    expect(ServiceAccount::query()->count())->toBe(0)->and(ServiceEvent::query()->count())->toBe(0);
});

it('assigns the chosen collector when the account is created', function (): void {
    $collector = Collector::factory()->create();

    $account = SubscriberData::account($this->admin, overrides: ['collector_id' => $collector->id]);

    expect($account->assigned_collector_id)->toBe($collector->id)
        ->and($account->assignments()->whereNull('effective_to')->count())->toBe(1);
});

it('rolls back the whole account when the collector is not usable', function (): void {
    $inactive = Collector::factory()->create(['is_active' => false]);

    expect(fn () => SubscriberData::account($this->admin, overrides: ['collector_id' => $inactive->id]))->toThrow(ValidationException::class);

    expect(ServiceAccount::query()->count())->toBe(0)->and(ServiceEvent::query()->count())->toBe(0);
});

function changeStatus(User $actor, ServiceAccount $account, ServiceStatus $to, string $reason = 'Because'): ServiceAccount
{
    return app(ChangeServiceStatus::class)->handle($actor, $account->id, $to, $reason);
}

describe('status changes (FR-SUB-008, INV-21)', function (): void {
    it('activates, suspends and reconnects, writing one event and one audit row each', function (): void {
        $account = SubscriberData::account($this->admin);

        changeStatus($this->admin, $account, ServiceStatus::Active, 'Installed');
        $activated = $account->refresh()->activation_date;
        changeStatus($this->admin, $account, ServiceStatus::Suspended, 'Unpaid');
        changeStatus($this->admin, $account, ServiceStatus::Active, 'Paid');

        $account->refresh();
        expect($account->status)->toBe(ServiceStatus::Active)
            // A reconnection keeps the first activation date.
            ->and($account->activation_date?->toDateString())->toBe($activated?->toDateString())
            ->and($activated)->not->toBeNull();

        $events = ServiceEvent::query()->where('service_account_id', $account->id)->orderBy('id')->get();
        expect($events->pluck('event_type')->map->value->all())->toBe(['CREATED', 'ACTIVATED', 'SUSPENDED', 'RECONNECTED'])
            ->and($events->pluck('notes')->all())->toBe(['Created on plan '.$account->plan->code, 'Installed', 'Unpaid', 'Paid'])
            ->and($events->pluck('actor_user_id')->unique()->all())->toBe([$this->admin->id]);

        $suspend = $events[2];
        expect($suspend->from_status)->toBe(ServiceStatus::Active)->and($suspend->to_status)->toBe(ServiceStatus::Suspended);

        expect(AuditLog::query()->where('action', 'service_account.status_changed')->count())->toBe(3);
    });

    it('makes TERMINATED final and refuses moves that are not allowed', function (): void {
        $account = SubscriberData::account($this->admin);

        expect(fn () => changeStatus($this->admin, $account, ServiceStatus::Suspended))->toThrow(ValidationException::class); // PENDING -> SUSPENDED

        changeStatus($this->admin, $account, ServiceStatus::Terminated, 'Cancelled install'); // PENDING -> TERMINATED
        expect(fn () => changeStatus($this->admin, $account, ServiceStatus::Active))->toThrow(ValidationException::class);

        // The refused moves left no trace.
        expect(ServiceEvent::query()->where('service_account_id', $account->id)->count())->toBe(2)
            ->and($account->refresh()->status)->toBe(ServiceStatus::Terminated);
    });

    it('needs a reason', function (): void {
        $account = SubscriberData::account($this->admin);

        expect(fn () => changeStatus($this->admin, $account, ServiceStatus::Active, '  '))->toThrow(ValidationException::class);
        expect($account->refresh()->status)->toBe(ServiceStatus::Pending);
    });

    it('lets a Technician complete an installation but nothing else', function (): void {
        $technician = Users::make('tony', RoleCode::Technician);
        $account = SubscriberData::account($this->admin);

        changeStatus($technician, $account, ServiceStatus::Active, 'Installation done');
        expect($account->refresh()->status)->toBe(ServiceStatus::Active);

        expect(fn () => changeStatus($technician, $account, ServiceStatus::Suspended))->toThrow(AuthorizationException::class)
            ->and(fn () => changeStatus($technician, $account, ServiceStatus::Terminated))->toThrow(AuthorizationException::class);

        $pending = SubscriberData::account($this->admin);
        expect(fn () => changeStatus($technician, $pending, ServiceStatus::Terminated))->toThrow(AuthorizationException::class);
        expect($pending->refresh()->status)->toBe(ServiceStatus::Pending);
    });

    it('refuses roles with neither service.manage nor service.execute (AT-10)', function (RoleCode $role): void {
        $user = Users::make('someone', $role);
        $account = SubscriberData::account($this->admin);

        expect(fn () => changeStatus($user, $account, ServiceStatus::Active))->toThrow(AuthorizationException::class);
        expect($account->refresh()->status)->toBe(ServiceStatus::Pending);
    })->with([
        'cashier' => RoleCode::Cashier,
        'supervisor' => RoleCode::CollectionSupervisor,
        'auditor' => RoleCode::Auditor,
        'viewer' => RoleCode::Viewer,
    ]);
});

it('refuses account creation to roles without service.manage (AT-10)', function (RoleCode $role): void {
    $user = Users::make('someone', $role);
    $subscriber = SubscriberData::subscriber($this->admin);

    expect(fn () => app(CreateServiceAccount::class)->handle($user, [
        'subscriber_id' => $subscriber->id,
        'plan_id' => ServicePlan::factory()->create()->id,
        'service_address_id' => $subscriber->addresses()->first()->id,
        'billing_start_date' => '2026-10-01',
    ]))->toThrow(AuthorizationException::class);

    expect(ServiceAccount::query()->count())->toBe(0);
})->with([
    'cashier' => RoleCode::Cashier,
    'supervisor' => RoleCode::CollectionSupervisor,
    'technician' => RoleCode::Technician,
    'viewer' => RoleCode::Viewer,
]);

it('never deletes: there is no delete method on subscribers or accounts to call (INV-19)', function (): void {
    $account = SubscriberData::account($this->admin);

    // The database refuses it even if code tried: events and FKs reference the account.
    expect(fn () => DB::transaction(fn () => ServiceAccount::query()->whereKey($account->id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => Subscriber::query()->whereKey($account->subscriber_id)->delete()))
        ->toThrow(QueryException::class);
});
