<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Livewire\Subscribers\SubscriberForm;
use App\Livewire\Subscribers\SubscriberList;
use App\Livewire\Subscribers\SubscriberProfile;
use App\Models\AuditLog;
use App\Models\CollectionArea;
use App\Models\Subscriber;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-001, FR-SUB-003, FR-SUB-004, FR-SUB-009, FR-SUB-012, FR-UI-002, NFR-02, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->admin = Users::make('admin', RoleCode::Administrator);
});

describe('All Subscribers', function (): void {
    it('lists subscribers and pages 25 at a time on the server (NFR-02)', function (): void {
        Subscriber::factory()->count(30)->create();

        Livewire::actingAs($this->admin)->test(SubscriberList::class)
            ->assertViewHas('subscribers', fn ($page) => $page->total() === 30 && count($page->items()) === 25)
            ->assertSee('30 subscribers');
    });

    it('searches by name, number, phone and address (FR-SUB-003)', function (): void {
        $ana = SubscriberData::subscriber($this->admin, [
            'full_name' => 'Ana Reyes',
            'contacts' => [['contact_type' => 'MOBILE', 'value' => '0917 555 0101']],
            'addresses' => [['address_type' => 'SERVICE', 'line1' => '45 Sampaguita Lane', 'city' => 'Malaybalay City']],
        ]);
        SubscriberData::subscriber($this->admin, ['full_name' => 'Ben Santos']);

        foreach (['Ana Reyes', 'reyes', $ana->account_no, '+63 917 555 0101', 'sampaguita lane'] as $term) {
            Livewire::actingAs($this->admin)->test(SubscriberList::class)
                ->set('search', $term)
                ->assertSee('Ana Reyes')
                ->assertDontSee('Ben Santos');
        }
    });

    it('filters by status and area and clears the filters', function (): void {
        $area = CollectionArea::factory()->create(['name' => 'Riverside']);
        $inArea = SubscriberData::subscriber($this->admin, ['full_name' => 'River Person', 'collection_area_id' => $area->id]);
        $other = SubscriberData::subscriber($this->admin, ['full_name' => 'Elsewhere Person']);
        app(ChangeSubscriberStatus::class)->handle($this->admin, $other->id, SubscriberStatus::Inactive, 'Paused');

        $page = Livewire::actingAs($this->admin)->test(SubscriberList::class);

        $page->set('area', (string) $area->id)->assertSee('River Person')->assertDontSee('Elsewhere Person');
        $page->call('clear')->assertSee('River Person')->assertSee('Elsewhere Person');
        $page->set('status', 'INACTIVE')->assertSee('Elsewhere Person')->assertDontSee('River Person');
    });

    it('ignores tampered filter values instead of failing', function (): void {
        Subscriber::factory()->count(3)->create();

        Livewire::actingAs($this->admin)->test(SubscriberList::class)
            ->set('status', "'; DROP TABLE subscribers; --")
            ->set('area', 'abc')
            ->set('collector', '1 OR 1=1')
            ->assertOk()
            ->assertViewHas('subscribers', fn ($page) => $page->total() === 3);

        expect(Subscriber::query()->count())->toBe(3);
    });

    it('shows a helpful empty state', function (): void {
        Livewire::actingAs($this->admin)->test(SubscriberList::class)
            ->set('search', 'nobody-by-this-name')
            ->assertSee('No subscribers match');
    });

    it('is open to read-only roles but offers no register button to them', function (): void {
        Livewire::actingAs(Users::make('cash', RoleCode::Cashier))->test(SubscriberList::class)
            ->assertOk()->assertDontSee('New subscriber');
        Livewire::actingAs($this->admin)->test(SubscriberList::class)->assertSee('New subscriber');
    });

    it('is refused for the Technician', function (): void {
        Livewire::actingAs(Users::make('tony', RoleCode::Technician))->test(SubscriberList::class)->assertForbidden();
    });
});

function fillForm($component, array $overrides = [])
{
    $values = [...[
        'full_name' => 'Maria Clara',
        'billing_due_day' => '10',
        'contacts.0.value' => '0917 555 0303',
        'addresses.0.line1' => '12 Rizal Street',
        'addresses.0.city' => 'Malaybalay City',
    ], ...$overrides];

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

describe('New and Edit Subscriber', function (): void {
    it('registers a subscriber and opens the new profile (FR-SUB-001)', function (): void {
        $component = fillForm(Livewire::actingAs($this->admin)->test(SubscriberForm::class))->call('save');

        $subscriber = Subscriber::query()->where('full_name', 'Maria Clara')->sole();
        $component->assertHasNoErrors()->assertRedirect(route('subscribers.show', $subscriber->id));

        expect($subscriber->account_no)->toMatch('/^SUB-\d{6}$/')
            ->and($subscriber->contacts()->sole()->value)->toBe('09175550303')
            ->and(AuditLog::query()->where('action', 'subscriber.created')->count())->toBe(1);
    });

    it('shows errors inline, only for fields the user has left (FR-SUB-001)', function (): void {
        $component = Livewire::actingAs($this->admin)->test(SubscriberForm::class);

        $component->assertHasNoErrors();

        $component->set('full_name', 'x')->assertHasErrors(['full_name'])->assertHasNoErrors(['contacts.0.value', 'addresses.0.line1']);
        $component->set('contacts.0.value', '12')->assertHasErrors(['full_name', 'contacts.0.value']);
        $component->set('full_name', 'Maria Clara')->assertHasNoErrors(['full_name'])->assertHasErrors(['contacts.0.value']);
    });

    it('shows every error when saving an empty form and creates nothing', function (): void {
        Livewire::actingAs($this->admin)->test(SubscriberForm::class)
            ->call('save')
            ->assertHasErrors(['full_name', 'contacts.0.value', 'addresses.0.line1', 'addresses.0.city']);

        expect(Subscriber::query()->count())->toBe(0);
    });

    it('warns about a probable duplicate and needs "create anyway" (FR-SUB-012)', function (): void {
        $existing = SubscriberData::subscriber($this->admin, [
            'full_name' => 'Elena Cruz',
            'contacts' => [['contact_type' => 'MOBILE', 'value' => '0917 555 0404']],
        ]);

        $component = fillForm(Livewire::actingAs($this->admin)->test(SubscriberForm::class), [
            'full_name' => 'Somebody Else',
            'contacts.0.value' => '+63 917-555-0404',
        ]);

        $component->assertSee('Possible duplicate')->assertSee('Elena Cruz')->assertSee('Same contact number')
            ->assertSee(route('subscribers.show', $existing->id), false);

        $component->call('save')->assertHasErrors(['form']);
        expect(Subscriber::query()->count())->toBe(1);

        $component->set('confirm_duplicate', true)->call('save')->assertHasNoErrors();
        expect(Subscriber::query()->count())->toBe(2);
    });

    it('offers to reactivate an inactive duplicate instead of a second record (FR-SUB-012)', function (): void {
        $existing = SubscriberData::subscriber($this->admin, ['contacts' => [['contact_type' => 'MOBILE', 'value' => '09175550505']]]);
        app(ChangeSubscriberStatus::class)->handle($this->admin, $existing->id, SubscriberStatus::Inactive, 'Moved');

        $component = fillForm(Livewire::actingAs($this->admin)->test(SubscriberForm::class), ['contacts.0.value' => '09175550505'])
            ->assertSee('Reactivate');

        $component->call('reactivate', $existing->id)->assertRedirect(route('subscribers.show', $existing->id));

        expect($existing->refresh()->status)->toBe(SubscriberStatus::Active)->and(Subscriber::query()->count())->toBe(1);
    });

    it('adds and removes contact and address rows, keeping at least one', function (): void {
        $component = Livewire::actingAs($this->admin)->test(SubscriberForm::class)
            ->call('addContact')->call('addAddress')
            ->assertCount('contacts', 2)->assertCount('addresses', 2)
            ->set('primaryContact', 1)
            ->call('removeContact', 0)
            ->assertCount('contacts', 1)->assertSet('primaryContact', 0)
            ->call('removeContact', 0)->assertCount('contacts', 1)
            ->call('removeAddress', 1)->call('removeAddress', 0)->assertCount('addresses', 1);

        $component->assertOk();
    });

    it('loads an existing subscriber for editing and saves changes (FR-SUB-001)', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Old Name']);

        Livewire::actingAs($this->admin)->test(SubscriberForm::class, ['subscriber' => $subscriber->id])
            ->assertSet('full_name', 'Old Name')
            ->assertSet('subscriberId', $subscriber->id)
            ->set('full_name', 'New Name')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('subscribers.show', $subscriber->id));

        expect($subscriber->refresh()->full_name)->toBe('New Name')
            ->and(AuditLog::query()->where('action', 'subscriber.updated')->count())->toBe(1);
    });

    it('does not warn that a subscriber duplicates themselves when editing', function (): void {
        $subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Solo Person']);

        Livewire::actingAs($this->admin)->test(SubscriberForm::class, ['subscriber' => $subscriber->id])
            ->set('full_name', 'Solo Person')
            ->assertDontSee('Possible duplicate');
    });

    it('cannot be pointed at another subscriber from the browser', function (): void {
        $mine = SubscriberData::subscriber($this->admin);
        $other = SubscriberData::subscriber($this->admin);

        expect(fn () => Livewire::actingAs($this->admin)->test(SubscriberForm::class, ['subscriber' => $mine->id])
            ->set('subscriberId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('is refused without subscriber.manage (AT-10)', function (RoleCode $role): void {
        Livewire::actingAs(Users::make('someone', $role))->test(SubscriberForm::class)->assertForbidden();
    })->with([
        'cashier' => RoleCode::Cashier,
        'supervisor' => RoleCode::CollectionSupervisor,
        'auditor' => RoleCode::Auditor,
        'technician' => RoleCode::Technician,
        'viewer' => RoleCode::Viewer,
    ]);
});

describe('Subscriber profile', function (): void {
    beforeEach(function (): void {
        $this->subscriber = SubscriberData::subscriber($this->admin, ['full_name' => 'Ana Reyes', 'notes' => 'Prefers mornings']);
    });

    it('shows the overview with details, contacts and addresses', function (): void {
        Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->assertSee('Ana Reyes')->assertSee($this->subscriber->account_no)->assertSee('Prefers mornings')
            ->assertSee($this->subscriber->contacts()->first()->value)->assertSee('Rizal Street');
    });

    it('offers all nine tabs to a user who holds every permission (FR-SUB-009)', function (): void {
        $owner = Users::make('owner', RoleCode::Owner);

        Livewire::actingAs($owner)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->assertViewHas('tabs', fn (array $tabs) => array_values($tabs) === ['Overview', 'Services', 'Billing', 'Payments', 'Ledger', 'Collection', 'Service history', 'Documents', 'Audit']);
    });

    it('shows every tab without error, with empty states for the later phases (FR-UI-002)', function (): void {
        $owner = Users::make('owner', RoleCode::Owner);
        $expected = [
            'overview' => 'Details', 'services' => 'No service accounts yet', 'billing' => 'No invoices yet',
            'payments' => 'No payments yet', 'ledger' => 'No ledger entries yet', 'collection' => 'No collection activity yet',
            'history' => 'No service events', 'documents' => 'No documents on file', 'audit' => 'Audit trail not shown here yet',
        ];

        $component = Livewire::actingAs($owner)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id]);

        foreach ($expected as $tab => $text) {
            $component->call('setTab', $tab)->assertOk()->assertSee($text);
        }
    });

    it('hides tabs the user may not read and falls back to Overview (FR-SUB-009)', function (): void {
        $component = Livewire::actingAs(Users::make('cash', RoleCode::Cashier))->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->assertViewHas('tabs', fn (array $tabs) => ! array_key_exists('audit', $tabs));

        $component->call('setTab', 'audit')->assertViewHas('activeTab', 'overview')->assertSee('Details');
        $component->call('setTab', 'nonsense')->assertViewHas('activeTab', 'overview');
    });

    it('lists service accounts with their rate, and the service history with reasons', function (): void {
        $account = SubscriberData::account($this->admin, $this->subscriber);
        app(ChangeServiceStatus::class)->handle($this->admin, $account->id, ServiceStatus::Active, 'Installation complete');

        $component = Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->call('setTab', 'services')
            ->assertSee($account->account_no)->assertSee('₱999.00')->assertSee('Active');

        $component->call('setTab', 'history')
            ->assertSee('Installation complete')->assertSee('Activated')->assertSee('Created');
    });

    it('changes the subscriber status with a reason and audits it (FR-SUB-004)', function (): void {
        Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->set('statusTo', 'INACTIVE')->set('reason', 'Moved abroad')
            ->call('changeStatus')
            ->assertHasNoErrors()->assertSee('Status changed to Inactive');

        expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Inactive)
            ->and(AuditLog::query()->where('action', 'subscriber.status_changed')->sole()->reason)->toBe('Moved abroad');
    });

    it('needs a reason and a valid status, and refuses impossible moves', function (): void {
        $component = Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id]);

        $component->set('statusTo', 'INACTIVE')->set('reason', '')->call('changeStatus')->assertHasErrors(['reason']);
        $component->set('statusTo', 'DELETED')->set('reason', 'Because')->call('changeStatus')->assertHasErrors(['statusTo']);
        $component->set('statusTo', 'ARCHIVED')->set('reason', 'Because')->call('changeStatus')->assertHasErrors(['status']);

        expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Active);
    });

    it('offers only the moves the subscriber can make, and no delete', function (): void {
        Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->assertViewHas('nextStatuses', fn (array $next) => $next === [SubscriberStatus::Inactive, SubscriberStatus::Terminated])
            ->assertDontSee('Delete');
    });

    it('does not let a read-only role change the status (AT-10)', function (): void {
        Livewire::actingAs(Users::make('cash', RoleCode::Cashier))->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->assertDontSee('Change subscriber status')
            ->set('statusTo', 'INACTIVE')->set('reason', 'Sneaky')
            ->call('changeStatus')->assertForbidden();

        expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Active);
    });

    it('cannot be pointed at another subscriber from the browser', function (): void {
        $other = SubscriberData::subscriber($this->admin);

        expect(fn () => Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->set('subscriberId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('is refused for the Technician and 404s for an unknown subscriber', function (): void {
        Livewire::actingAs(Users::make('tony', RoleCode::Technician))->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])->assertForbidden();

        Livewire::actingAs($this->admin)->test(SubscriberProfile::class, ['subscriber' => 999999])->assertNotFound();
    });
});
