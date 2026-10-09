<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\OperationalServiceQuery;
use App\Domain\Services\OperationalServiceRow;
use App\Livewire\Services\ServiceJobs;
use App\Models\AuditLog;
use App\Models\ServiceAccount;
use App\Models\ServiceEvent;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-013, PRD section 2 (Technician), INV-17, AT-10

uses(RefreshDatabase::class);

/** Words that would mean money reached the Technician's page. */
const FINANCIAL_WORDS = ['rate', 'rates', 'price', 'balance', 'invoice', 'invoices', 'payment', 'payments', 'receipt', 'ledger', 'arrears', 'overdue', 'amount', 'fee', 'centavos', 'billing', 'due day', 'due_day', 'gcash', 'peso'];

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->tony = Users::make('tony', RoleCode::Technician);

    // An account with distinctive money values, so any leak is easy to spot.
    $plan = ServicePlan::factory()->create(['name' => 'Internet 20', 'price_centavos' => Money::fromCentavos(123456)]);
    $this->subscriber = SubscriberData::subscriber($this->admin, [
        'full_name' => 'Ana Reyes',
        'billing_due_day' => 17,
        'notes' => 'Owes a lot of money, ask about the unpaid invoice',
        'contacts' => [['contact_type' => 'MOBILE', 'value' => '09175550777']],
        'addresses' => [['address_type' => 'SERVICE', 'line1' => '45 Sampaguita Lane', 'barangay' => 'Casisang', 'city' => 'Malaybalay City']],
    ]);
    $this->account = SubscriberData::account($this->admin, $this->subscriber, $plan);
    app(ChangeServiceStatus::class)->handle($this->admin, $this->account->id, ServiceStatus::Active, 'Unpaid invoices of 1,234.56 pesos cleared');
});

/** @return list<string> the financial words found in a text, whole words only */
function financialWordsIn(string $text): array
{
    $plain = strtolower(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $text)));

    return array_values(array_filter(FINANCIAL_WORDS, fn (string $word) => preg_match('/\b'.preg_quote($word, '/').'\b/', $plain) === 1));
}

describe('what the Technician sees (FR-SUB-013)', function (): void {
    it('lists the operational fields: account, subscriber, address, plan, status, since and instruction', function (): void {
        $pending = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Ben Santos']));

        $this->actingAs($this->tony)->get(route('services.operational'))
            ->assertOk()
            ->assertSee($this->account->account_no)->assertSee('Ana Reyes')->assertSee('45 Sampaguita Lane, Casisang, Malaybalay City')
            ->assertSee('Internet 20')->assertSee('Active')->assertSee('In service')
            ->assertSee($pending->account_no)->assertSee('Install the service');
    });

    it('puts waiting installations first, then disconnected, then working services', function (): void {
        $suspended = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Carlo Mendoza']));
        app(ChangeServiceStatus::class)->handle($this->admin, $suspended->id, ServiceStatus::Active, 'Installed');
        app(ChangeServiceStatus::class)->handle($this->admin, $suspended->id, ServiceStatus::Suspended, 'Disconnect');
        $pending = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Zed Last']));

        Livewire::actingAs($this->tony)->test(ServiceJobs::class)
            ->assertViewHas('rows', fn ($page) => collect($page->items())->pluck('accountNo')->all() === [$pending->account_no, $suspended->account_no, $this->account->account_no]);
    });

    it('hides terminated services unless asked, and searches name, number and address', function (): void {
        $gone = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Gil Ramos']));
        app(ChangeServiceStatus::class)->handle($this->admin, $gone->id, ServiceStatus::Terminated, 'Cancelled');

        $component = Livewire::actingAs($this->tony)->test(ServiceJobs::class)->assertDontSee($gone->account_no);
        $component->set('status', 'TERMINATED')->assertSee($gone->account_no)->assertDontSee($this->account->account_no);

        $component->call('clear');
        foreach (['Reyes', $this->account->account_no, 'sampaguita'] as $term) {
            $component->set('search', $term)->assertSee($this->account->account_no)->assertDontSee($gone->account_no);
        }
    });

    it('shows no money, balance, invoice or payment word or value anywhere on the page', function (): void {
        $html = $this->actingAs($this->tony)->get(route('services.operational'))->assertOk()->getContent();

        foreach (['1,234.56', '1234.56', '123456', '₱', '17'] as $value) {
            // 17 is the due day; it may only appear as part of an unrelated number, never alone in a cell.
            if ($value === '17') {
                expect($html)->not->toMatch('/>\s*17\s*</');

                continue;
            }
            expect($html)->not->toContain($value);
        }

        expect(financialWordsIn($html))->toBe([]);
        // Nothing from the subscriber's notes, contacts or the typed reasons either.
        expect($html)->not->toContain('Owes a lot')->not->toContain('09175550777')->not->toContain('cleared');
    });

    it('keeps money out of the Livewire data sent to the browser too', function (): void {
        $component = Livewire::actingAs($this->tony)->test(ServiceJobs::class);

        $state = json_encode($component->instance()->all(), JSON_THROW_ON_ERROR);
        $snapshot = json_encode($component->snapshot ?? [], JSON_THROW_ON_ERROR);

        foreach ([$state, $snapshot, $component->html()] as $payload) {
            expect($payload)->not->toContain('123456')->not->toContain('1,234.56')->not->toContain('current_rate');
        }
    });
});

describe('enforced in the query and the response shape, not the view', function (): void {
    it('selects and joins no financial column or table', function (): void {
        $sql = strtolower((new OperationalServiceQuery)->query()->toSql());

        foreach (['current_rate_centavos', 'price_centavos', 'installation_fee', 'billing_due_day', 'billing_start_date', 'invoice', 'payment', 'ledger', 'receipt', 'adjustment', 'subscriber_contacts', 'notes', 'full_name as full_name'] as $forbidden) {
            expect($sql)->not->toContain($forbidden);
        }

        // And the selected column list is exactly the whitelist.
        $columns = collect((new OperationalServiceQuery)->query()->columns)->map(fn ($c) => is_string($c) ? $c : 'subquery')->all();
        expect($columns)->toBe(['sa.id', 'sa.account_no', 'sa.status', 's.full_name as subscriber_name', 'a.line1', 'a.barangay', 'a.city', 'p.name as plan_name', 't.name as service_type', 'subquery']);
    });

    it('returns rows that have no financial property', function (): void {
        $properties = array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(OperationalServiceRow::class))->getProperties());

        expect($properties)->toBe(['id', 'accountNo', 'subscriberName', 'serviceAddress', 'planName', 'serviceType', 'status', 'statusDate']);
        foreach ($properties as $name) {
            expect($name)->not->toMatch('/rate|price|fee|amount|balance|centavo|invoice|payment|due|billing|money/i');
        }

        $page = (new OperationalServiceQuery)->paginate($this->tony);
        expect($page->items()[0])->toBeInstanceOf(OperationalServiceRow::class);

        $raw = (array) (new OperationalServiceQuery)->query()->first();
        expect(array_keys($raw))->not->toContain('current_rate_centavos')->not->toContain('billing_due_day');
    });

    it('is given only to roles with service.view and checked in the query class', function (): void {
        $outsider = Users::make('nobody', RoleCode::Viewer);
        $outsider->roles()->detach();

        expect(fn () => (new OperationalServiceQuery)->paginate($outsider))->toThrow(AuthorizationException::class);
    });
});

describe('marking an installation done', function (): void {
    it('lets the Technician complete a Pending installation with a note', function (): void {
        $pending = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin, ['full_name' => 'Ben Santos']));

        Livewire::actingAs($this->tony)->test(ServiceJobs::class)
            ->assertSee('Mark installed')
            ->call('select', $pending->id)
            ->set('note', 'modem fitted')
            ->call('markInstalled')
            ->assertHasNoErrors()
            ->assertSee("{$pending->account_no} is now active");

        $pending->refresh();
        expect($pending->status)->toBe(ServiceStatus::Active)->and($pending->activation_date)->not->toBeNull();

        $event = ServiceEvent::query()->where('service_account_id', $pending->id)->where('event_type', 'ACTIVATED')->sole();
        expect($event->actor_user_id)->toBe($this->tony->id)->and($event->notes)->toBe('Installation completed: modem fitted');
        expect(AuditLog::query()->where('action', 'service_account.status_changed')->where('actor_user_id', $this->tony->id)->count())->toBe(1);
    });

    it('does not let the Technician touch a service that is not waiting for installation', function (): void {
        // The account is already ACTIVE: the Action allows a Technician PENDING -> ACTIVE only.
        Livewire::actingAs($this->tony)->test(ServiceJobs::class)
            ->call('select', $this->account->id)
            ->call('markInstalled')
            ->assertForbidden();

        expect($this->account->refresh()->status)->toBe(ServiceStatus::Active);
    });

    it('offers no installation button to roles without service.execute (AT-10)', function (): void {
        $pending = SubscriberData::account($this->admin, SubscriberData::subscriber($this->admin));

        $cashier = Users::make('cash', RoleCode::Cashier);
        Livewire::actingAs($cashier)->test(ServiceJobs::class)->assertOk()->assertSee($pending->account_no)->assertDontSee('Mark installed');
        Livewire::actingAs(Users::make('cash2', RoleCode::Cashier))->test(ServiceJobs::class)->call('select', $pending->id)->assertForbidden();
        Livewire::actingAs(Users::make('cash3', RoleCode::Cashier))->test(ServiceJobs::class)->call('markInstalled')->assertForbidden();

        expect($pending->refresh()->status)->toBe(ServiceStatus::Pending);
    });

    it('cannot be pointed at another account from the browser', function (): void {
        expect(fn () => Livewire::actingAs($this->tony)->test(ServiceJobs::class)->set('selectedId', $this->account->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('returns 404 for an account that does not exist', function (): void {
        Livewire::actingAs($this->tony)->test(ServiceJobs::class)->call('select', 999999)->assertNotFound();
    });
});

describe('access', function (): void {
    it('is open to every role that holds service.view, and closed to guests', function (): void {
        foreach ([RoleCode::Owner, RoleCode::Administrator, RoleCode::Cashier, RoleCode::CollectionSupervisor, RoleCode::Auditor, RoleCode::Technician, RoleCode::Viewer] as $i => $role) {
            $this->actingAs(Users::make("user{$i}", $role))->get(route('services.operational'))->assertOk();
        }

        auth()->logout();
        $this->get(route('services.operational'))->assertRedirect(route('login'));
    });

    it('is the Technician\'s only sidebar item besides the dashboard', function (): void {
        $this->actingAs($this->tony)->get(route('home'))
            ->assertSee(route('services.operational'), false)
            ->assertDontSee(route('subscribers.index'), false)
            ->assertDontSee(route('service-accounts.index'), false);
    });

    it('shows no service-account row to anyone else through the Technician screen as a back door to rates', function (): void {
        // Even an Administrator on this page sees the same operational fields.
        $html = $this->actingAs($this->admin)->get(route('services.operational'))->getContent();

        expect($html)->not->toContain('1,234.56')->and(financialWordsIn($html))->not->toContain('rate');
    });
});

it('keeps the account fixture a real, billed service for the checks above', function (): void {
    expect(ServiceAccount::query()->findOrFail($this->account->id)->current_rate_centavos->centavos)->toBe(123456);
});
