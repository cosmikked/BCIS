<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\AssignCollector;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Livewire\Services\Plans;
use App\Livewire\Services\ServiceAccounts;
use App\Livewire\Services\ServiceHistory;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-002, FR-SUB-005, FR-SUB-006, FR-SUB-007, FR-SUB-008, FR-SUB-011, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->admin = Users::make('admin', RoleCode::Administrator);
});

describe('Plans', function (): void {
    it('lists plans with money in pesos and filters by type', function (): void {
        ServicePlan::factory()->create(['name' => 'Internet 20', 'price_centavos' => Money::fromCentavos(99900)]);
        ServicePlan::factory()->cable()->create(['name' => 'Cable Plus', 'price_centavos' => Money::fromCentavos(100000)]);

        $component = Livewire::actingAs($this->admin)->test(Plans::class)
            ->assertSee('Internet 20')->assertSee('₱999.00')->assertSee('Cable Plus')->assertSee('₱1,000.00');

        $component->set('typeFilter', (string) ServiceType::query()->where('code', 'CABLE')->value('id'))
            ->assertSee('Cable Plus')->assertDontSee('Internet 20');
    });

    it('creates, edits and deactivates a plan (FR-SUB-005)', function (): void {
        $internet = (string) ServiceType::query()->where('code', 'INTERNET')->value('id');

        $component = Livewire::actingAs($this->admin)->test(Plans::class)
            ->call('startCreate')
            ->set('code', 'int-99')->set('name', 'Internet 99')->set('serviceTypeId', $internet)->set('price', '1,299.00')->set('speedMbps', '50')
            ->call('save')->assertHasNoErrors()->assertSee('Plan INT-99 created');

        $plan = ServicePlan::query()->where('code', 'INT-99')->sole();
        expect($plan->price_centavos->centavos)->toBe(129900);

        $component->call('select', $plan->id)->set('price', '1,399.00')->set('reason', 'Price review')
            ->call('save')->assertHasNoErrors()->assertSee('Existing service accounts keep their rate');
        expect($plan->refresh()->price_centavos->centavos)->toBe(139900);

        $component->set('reason', 'Retired')->call('toggleActive')->assertHasNoErrors();
        expect($plan->refresh()->is_active)->toBeFalse();
    });

    it('shows the field errors from the Action', function (): void {
        Livewire::actingAs($this->admin)->test(Plans::class)
            ->call('startCreate')
            ->set('code', 'int-x')->set('name', 'Internet X')->set('serviceTypeId', (string) ServiceType::query()->where('code', 'INTERNET')->value('id'))->set('price', '12.345')
            ->call('save')->assertHasErrors(['price']);

        expect(ServicePlan::query()->count())->toBe(0);
    });

    it('keeps the code and type fixed when editing, and needs a reason', function (): void {
        $plan = ServicePlan::factory()->create();

        Livewire::actingAs($this->admin)->test(Plans::class)
            ->call('select', $plan->id)->set('name', 'Renamed')
            ->call('save')->assertHasErrors(['reason']);
    });

    it('lets a Cashier read the list but not change anything (AT-10)', function (): void {
        $plan = ServicePlan::factory()->create(['name' => 'Internet 20']);

        Livewire::actingAs(Users::make('cash', RoleCode::Cashier))->test(Plans::class)
            ->assertOk()->assertSee('Internet 20')->assertDontSee('New plan')
            ->call('startCreate')->assertForbidden();

        Livewire::actingAs(Users::make('cash2', RoleCode::Cashier))->test(Plans::class)->call('select', $plan->id)->assertForbidden();
        Livewire::actingAs(Users::make('cash3', RoleCode::Cashier))->test(Plans::class)->call('toggleActive')->assertForbidden();
        expect($plan->refresh()->is_active)->toBeTrue();
    });

    it('is refused for the Technician', function (): void {
        Livewire::actingAs(Users::make('tony', RoleCode::Technician))->test(Plans::class)->assertForbidden();
    });
});

describe('Service accounts', function (): void {
    it('lists accounts with plan, rate, status and collector, paged on the server', function (): void {
        $collector = Collector::factory()->create(['full_name' => 'Rico Dela Cruz']);
        $account = SubscriberData::account($this->admin, overrides: ['collector_id' => $collector->id]);

        Livewire::actingAs($this->admin)->test(ServiceAccounts::class)
            ->assertSee($account->account_no)->assertSee($account->subscriber->full_name)->assertSee('₱999.00')
            ->assertSee('Pending')->assertSee('Rico Dela Cruz')
            ->assertViewHas('accounts', fn ($page) => $page->total() === 1 && $page->perPage() === 20);
    });

    it('searches and filters', function (): void {
        $ana = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes']));
        $ben = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Ben Santos']), ServicePlan::factory()->cable()->create());
        app(ChangeServiceStatus::class)->handle($this->admin, $ben->id, ServiceStatus::Active, 'Installed');

        $component = Livewire::actingAs($this->admin)->test(ServiceAccounts::class);

        $component->set('search', 'Ana')->assertSee($ana->account_no)->assertDontSee($ben->account_no);
        $component->set('search', $ben->account_no)->assertSee($ben->account_no)->assertDontSee($ana->account_no);
        $component->call('clear')->set('status', 'ACTIVE')->assertSee($ben->account_no)->assertDontSee($ana->account_no);
        $component->call('clear')->set('type', (string) $ben->plan->service_type_id)->assertSee($ben->account_no)->assertDontSee($ana->account_no);
        $component->call('clear')->set('subscriber', (string) $ana->subscriber_id)->assertSee($ana->account_no)->assertDontSee($ben->account_no);
    });

    it('creates an account from the form: copies the rate and stays Pending (FR-SUB-007)', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Maria Clara', 'billing_due_day' => 12]);
        $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(149900), 'name' => 'Combo Standard']);
        $collector = Collector::factory()->create();

        $component = Livewire::actingAs($this->admin)->test(ServiceAccounts::class)
            ->call('startCreate')
            ->set('subscriberLookup', 'Maria')
            ->assertSee('Maria Clara')
            ->call('chooseSubscriber', $subscriber->id)
            ->set('planId', (string) $plan->id)
            ->set('serviceAddressId', (string) $subscriber->addresses()->first()->id)
            ->set('billingStartDate', '2026-10-01')
            ->set('newCollectorId', (string) $collector->id)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('It stays Pending until it is activated');

        $account = ServiceAccount::query()->sole();
        expect($account->status)->toBe(ServiceStatus::Pending)
            ->and($account->current_rate_centavos->centavos)->toBe(149900)
            ->and($account->billing_due_day)->toBe(12)
            ->and($account->assigned_collector_id)->toBe($collector->id);

        $component->assertSet('selectedId', $account->id)->assertSet('creating', false);
    });

    it('takes an optional installation fee on the create form (PRD 19 #6)', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);
        $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(149900)]);

        $component = Livewire::actingAs($this->admin)->test(ServiceAccounts::class)
            ->call('startCreate')->call('chooseSubscriber', $subscriber->id)
            ->set('planId', (string) $plan->id)
            ->set('serviceAddressId', (string) $subscriber->addresses()->first()->id)
            ->set('billingStartDate', '2026-10-01')
            ->set('installationFee', '5OO');

        $component->call('create')->assertHasErrors(['installation_fee']);
        expect(ServiceAccount::query()->count())->toBe(0);

        $component->set('installationFee', '500.00')->call('create')->assertHasNoErrors()->assertSet('installationFee', '');

        $account = ServiceAccount::query()->sole();
        expect($account->installation_fee_centavos->centavos)->toBe(50000)
            ->and($account->installation_fee_billed_at)->toBeNull();
    });

    it('shows the Action\'s errors on the create form and creates nothing', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin);

        Livewire::actingAs($this->admin)->test(ServiceAccounts::class)
            ->call('startCreate')->call('chooseSubscriber', $subscriber->id)
            ->call('create')
            ->assertHasErrors(['plan_id', 'service_address_id']);

        expect(ServiceAccount::query()->count())->toBe(0);
    });

    it('opens the create form for the subscriber named in the link from the profile', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Maria Clara']);

        Livewire::actingAs($this->admin)->withQueryParams(['subscriber' => (string) $subscriber->id, 'new' => '1'])->test(ServiceAccounts::class)
            ->assertSet('creating', true)->assertSet('newSubscriberId', (string) $subscriber->id)->assertSee('Maria Clara');
    });

    it('changes status with a reason, and the service history shows it (FR-SUB-008)', function (): void {
        $account = SubscriberData::account($this->admin);

        $component = Livewire::actingAs($this->admin)->test(ServiceAccounts::class)->call('select', $account->id)
            ->assertSee('Change status')
            ->set('statusTo', 'ACTIVE')->set('reason', 'Installation complete')
            ->call('changeStatus')->assertHasNoErrors()->assertSee("{$account->account_no} is now Active");

        expect($account->refresh()->status)->toBe(ServiceStatus::Active);
        $component->assertSee('Installation complete');
        expect(AuditLog::query()->where('action', 'service_account.status_changed')->count())->toBe(1);
    });

    it('only offers the status moves that are allowed and refuses a tampered one', function (): void {
        $account = SubscriberData::account($this->admin);

        $component = Livewire::actingAs($this->admin)->test(ServiceAccounts::class)->call('select', $account->id);

        $component->set('statusTo', 'SUSPENDED')->set('reason', 'Not allowed from pending')->call('changeStatus')->assertHasErrors(['status']);
        $component->set('statusTo', 'GONE')->set('reason', 'Nope')->call('changeStatus')->assertHasErrors(['statusTo']);
        $component->set('statusTo', 'ACTIVE')->set('reason', '')->call('changeStatus')->assertHasErrors(['reason']);

        expect($account->refresh()->status)->toBe(ServiceStatus::Pending);
    });

    it('assigns a collector and keeps the earlier one on record (FR-SUB-011)', function (): void {
        $account = SubscriberData::account($this->admin);
        $rico = Collector::factory()->create(['full_name' => 'Rico']);
        $mila = Collector::factory()->create(['full_name' => 'Mila']);
        app(AssignCollector::class)->forServiceAccount($this->admin, $account->id, $rico->id, '2026-09-01');

        Livewire::actingAs($this->admin)->test(ServiceAccounts::class)->call('select', $account->id)
            ->set('assignCollectorId', (string) $mila->id)->set('effectiveFrom', '2026-10-05')
            ->call('assignCollector')->assertHasNoErrors()->assertSee('Collector assigned');

        expect($account->refresh()->assigned_collector_id)->toBe($mila->id)
            ->and($account->assignments()->count())->toBe(2)
            ->and($account->assignments()->where('collector_id', $rico->id)->sole()->effective_to?->toDateString())->toBe('2026-10-04');
    });

    it('splits permissions: the Supervisor assigns collectors, a Cashier changes nothing (AT-10)', function (): void {
        $account = SubscriberData::account($this->admin);
        $collector = Collector::factory()->create();

        // A refused call ends that component, so each refusal uses a fresh one.
        $open = fn (string $username, RoleCode $role) => Livewire::actingAs(Users::make($username, $role))->test(ServiceAccounts::class)->call('select', $account->id);

        $open('sup1', RoleCode::CollectionSupervisor)->assertDontSee('Change status')->assertSee('Assign collector');
        $open('sup2', RoleCode::CollectionSupervisor)->set('statusTo', 'ACTIVE')->set('reason', 'Sneaky')->call('changeStatus')->assertForbidden();
        $open('sup3', RoleCode::CollectionSupervisor)->set('assignCollectorId', (string) $collector->id)->call('assignCollector')->assertHasNoErrors();
        expect($account->refresh()->assigned_collector_id)->toBe($collector->id);

        $open('cash1', RoleCode::Cashier)->assertDontSee('Change status')->assertDontSee('Assign collector')->assertDontSee('New service account');
        $open('cash2', RoleCode::Cashier)->call('startCreate')->assertForbidden();
        $open('cash3', RoleCode::Cashier)->set('assignCollectorId', (string) $collector->id)->call('assignCollector')->assertForbidden();
        $open('cash4', RoleCode::Cashier)->set('statusTo', 'ACTIVE')->set('reason', 'Sneaky')->call('changeStatus')->assertForbidden();

        expect($account->refresh()->status)->toBe(ServiceStatus::Pending);
    });

    it('cannot be pointed at another account from the browser', function (): void {
        $account = SubscriberData::account($this->admin);

        expect(fn () => Livewire::actingAs($this->admin)->test(ServiceAccounts::class)->set('selectedId', $account->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('is refused for the Technician', function (): void {
        Livewire::actingAs(Users::make('tony', RoleCode::Technician))->test(ServiceAccounts::class)->assertForbidden();
    });
});

describe('Service history', function (): void {
    it('lists the full sequence newest first, with who and why (FR-SUB-008)', function (): void {
        $account = SubscriberData::account($this->admin);
        app(ChangeServiceStatus::class)->handle($this->admin, $account->id, ServiceStatus::Active, 'Installation complete');
        app(ChangeServiceStatus::class)->handle($this->admin, $account->id, ServiceStatus::Suspended, 'Unpaid invoices');

        Livewire::actingAs($this->admin)->test(ServiceHistory::class)
            ->assertSeeInOrder(['Suspended', 'Unpaid invoices', 'Activated', 'Installation complete', 'Created'])
            ->assertSee($account->account_no)->assertSee($this->admin->full_name);
    });

    it('filters by account and by event, and has no way to change a row', function (): void {
        $a = SubscriberData::account($this->admin);
        $b = SubscriberData::account($this->admin);
        app(ChangeServiceStatus::class)->handle($this->admin, $b->id, ServiceStatus::Active, 'Done');

        $component = Livewire::actingAs($this->admin)->test(ServiceHistory::class);

        $component->set('account', (string) $a->id)->assertSee($a->account_no)->assertDontSee($b->account_no);
        $component->call('clear')->set('type', 'ACTIVATED')->assertSee($b->account_no)->assertDontSee($a->account_no);
        $component->call('clear')->set('type', 'NOT_A_TYPE')->assertOk();
        $component->call('clear')->set('from', 'garbage')->assertOk();

        expect(get_class_methods(ServiceHistory::class))->not->toContain('delete', 'update', 'edit');
    });

    it('is refused for the Technician', function (): void {
        Livewire::actingAs(Users::make('tony', RoleCode::Technician))->test(ServiceHistory::class)->assertForbidden();
    });
});
