<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\Subscriber;
use Database\Seeders\CollectionSetupSeeder;
use Database\Seeders\DemoSubscriberSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

// PRD section 18, NFR-10, NFR-14, FR-SUB-002, FR-SUB-008

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Users::make('owner', RoleCode::Owner);
    $this->seed([CollectionSetupSeeder::class, ServicePlanSeeder::class, DemoSubscriberSeeder::class]);
});

it('seeds 50 subscribers and 60 or more service accounts', function (): void {
    expect(Subscriber::query()->count())->toBe(50)
        ->and(ServiceAccount::query()->count())->toBeGreaterThanOrEqual(60);

    // Every account number and subscriber number is unique and from the sequences.
    expect(ServiceAccount::query()->distinct()->count('account_no'))->toBe(ServiceAccount::query()->count())
        ->and(Subscriber::query()->where('account_no', 'not like', 'SUB-%')->count())->toBe(0)
        ->and(ServiceAccount::query()->where('account_no', 'not like', 'SVC-%')->count())->toBe(0);
});

it('covers Internet, Cable and Combo accounts', function (): void {
    $types = ServiceAccount::query()->with('plan.serviceType')->get()->map(fn ($a) => $a->plan->serviceType->code)->unique()->sort()->values()->all();

    expect($types)->toBe(['CABLE', 'COMBO', 'INTERNET']);
});

it('creates the named demo subscribers in the states PRD section 18 needs', function (): void {
    $account = fn (string $name) => ServiceAccount::query()->whereHas('subscriber', fn ($q) => $q->where('full_name', $name))->with('plan')->sole();

    expect($account('Ana Reyes')->status)->toBe(ServiceStatus::Pending)
        ->and($account('Ana Reyes')->plan->code)->toBe('CMB-STD')
        ->and($account('Ana Reyes')->current_rate_centavos->centavos)->toBe(149900)
        ->and($account('Ben Santos')->current_rate_centavos->centavos)->toBe(99900)
        ->and($account('Carlo Mendoza')->status)->toBe(ServiceStatus::Suspended)
        ->and($account('Carlo Mendoza')->current_rate_centavos->centavos)->toBe(99900)
        ->and($account('Dina Flores')->status)->toBe(ServiceStatus::Suspended)
        ->and($account('Dina Flores')->current_rate_centavos->centavos)->toBe(100000)
        ->and($account('Elena Cruz')->current_rate_centavos->centavos)->toBe(149900)
        ->and($account('Elaine Cruz')->current_rate_centavos->centavos)->toBe(149900)
        ->and($account('Gil Ramos')->current_rate_centavos->centavos)->toBe(79900);
});

it('gives Rico Dela Cruz at least 40 accounts and Mila Santos the rest', function (): void {
    $rico = Collector::query()->where('full_name', 'Rico Dela Cruz')->firstOrFail();
    $mila = Collector::query()->where('full_name', 'Mila Santos')->firstOrFail();

    expect(ServiceAccount::query()->where('assigned_collector_id', $rico->id)->count())->toBeGreaterThanOrEqual(40)
        ->and(ServiceAccount::query()->where('assigned_collector_id', $mila->id)->count())->toBeGreaterThan(0)
        ->and(ServiceAccount::query()->whereNull('assigned_collector_id')->count())->toBe(0);
});

it('has a subscriber with two accounts and one who has left', function (): void {
    expect(Subscriber::query()->has('serviceAccounts', '>=', 2)->count())->toBeGreaterThan(0)
        ->and(Subscriber::query()->where('status', SubscriberStatus::Inactive->value)->count())->toBe(1)
        ->and(ServiceAccount::query()->where('status', ServiceStatus::Terminated->value)->count())->toBeGreaterThan(0);
});

it('wrote history and audit rows through the Actions (FR-SUB-008)', function (): void {
    $withoutEvents = ServiceAccount::query()->whereDoesntHave('events')->count();

    expect($withoutEvents)->toBe(0)
        ->and(ServiceEvent::query()->where('event_type', 'SUSPENDED')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'subscriber.created')->count())->toBe(50)
        ->and(AuditLog::query()->where('action', 'service_account.created')->count())->toBe(ServiceAccount::query()->count());
});

it('uses obviously synthetic phone numbers', function (): void {
    $numbers = DB::table('subscriber_contacts')->pluck('value');

    expect($numbers->every(fn (string $n) => str_starts_with($n, '0999000')))->toBeTrue();
});

it('does nothing when run again', function (): void {
    $this->seed(DemoSubscriberSeeder::class);

    expect(Subscriber::query()->count())->toBe(50);
});
