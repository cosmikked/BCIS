<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\Actions\UpdateSetting;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Livewire\Billing\CurrentBilling;
use App\Livewire\Billing\GenerateBilling;
use App\Livewire\Billing\Invoices;
use App\Models\AuditLog;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-001, FR-BIL-002, FR-BIL-004, FR-BIL-005, FR-BIL-008, FR-BIL-014, INV-17, TO-08

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);
    $this->technician = Users::make('tech', RoleCode::Technician);

    $this->plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
});

describe('who may open the billing screens (INV-17)', function (): void {
    it('lets billing.view read Current Billing and Invoices but not open Generate', function (): void {
        $this->actingAs($this->cashier)->get(route('billing.current'))->assertOk();
        $this->actingAs($this->cashier)->get(route('billing.invoices'))->assertOk();
        $this->actingAs($this->cashier)->get(route('billing.generate'))->assertForbidden();
    });

    it('refuses a role without billing.view everywhere', function (): void {
        foreach (['billing.current', 'billing.invoices', 'billing.generate'] as $route) {
            $this->actingAs($this->technician)->get(route($route))->assertForbidden();
        }
    });

    it('lets the Owner open all three', function (): void {
        foreach (['billing.current', 'billing.invoices', 'billing.generate'] as $route) {
            $this->actingAs($this->owner)->get(route($route))->assertOk();
        }
    });

    it('refuses a Livewire action after the permission is gone', function (): void {
        // The Cashier holds billing.view only, so the Generate screen cannot be mounted at all.
        Livewire::actingAs($this->cashier)->test(GenerateBilling::class)->assertForbidden();
        Livewire::actingAs($this->technician)->test(Invoices::class)->assertForbidden();
    });
});

describe('Generate Billing (FR-BIL-001, FR-BIL-004, FR-BIL-005)', function (): void {
    it('previews, generates, then finalizes a month through the Actions', function (): void {
        $a = BillingData::activeAccount($this->owner, plan: $this->plan);
        $suspended = BillingData::activeAccount($this->owner, plan: $this->plan);
        app(ChangeServiceStatus::class)->handle($this->owner, $suspended->id, ServiceStatus::Suspended, 'Non-payment');

        $component = Livewire::actingAs($this->owner)->test(GenerateBilling::class)
            ->set('period', '2026-10')
            ->call('preview')->assertHasNoErrors()
            ->assertSee('Preview for October 2026')
            ->assertSee($a->account_no)
            ->assertSee('₱999.00')
            ->assertSee('Suspended');

        // Preview saved nothing.
        expect(Invoice::query()->count())->toBe(0)->and(BillingCycle::query()->count())->toBe(0);

        $component->call('generate')->assertHasNoErrors()
            ->assertSee('1 draft invoices created')
            ->assertSee('Draft invoices');
        expect(Invoice::query()->where('status', 'DRAFT')->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(0);

        $component->call('finalize')->assertHasNoErrors()->assertSee('finalized: 1 invoices numbered');
        expect(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Unpaid)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and(BillingCycle::query()->sole()->status->value)->toBe('FINALIZED');
    });

    it('says an already billed month has nothing new', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $component = Livewire::actingAs($this->owner)->test(GenerateBilling::class)->set('period', '2026-10')
            ->call('preview')->call('generate');

        $component->call('preview')->call('generate')->assertSee('Nothing new to bill');
        expect(Invoice::query()->count())->toBe(1);
    });

    it('discards drafts only with a reason, and offers nothing for a finalized month', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $component = Livewire::actingAs($this->owner)->test(GenerateBilling::class)->set('period', '2026-10')
            ->call('preview')->call('generate');

        $component->call('discard')->assertHasErrors(['reason']);
        expect(Invoice::query()->count())->toBe(1);

        $component->set('discardReason', 'Rates need a check')->call('discard')->assertHasNoErrors();
        expect(Invoice::query()->count())->toBe(0);

        $component->call('preview')->call('generate')->call('finalize')
            ->assertSee('This month is finalized')
            ->assertDontSee('Discard drafts');
    });

    it('refuses a month that has not started and a malformed month', function (): void {
        $component = Livewire::actingAs($this->owner)->test(GenerateBilling::class);

        $component->set('period', '2026-11')->call('preview')->assertHasErrors(['period']);
        $component->set('period', 'garbage')->call('preview')->assertHasErrors(['period']);
        expect(BillingCycle::query()->count())->toBe(0);
    });

    it('does not trust the browser to say which cycle or whether it is previewing', function (): void {
        $component = Livewire::actingAs($this->owner)->test(GenerateBilling::class);

        expect(fn () => $component->set('previewing', true))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('message', 'forged'))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('audits the generate and finalize it ran', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        Livewire::actingAs($this->owner)->test(GenerateBilling::class)->set('period', '2026-10')
            ->call('preview')->call('generate')->call('finalize');

        expect(AuditLog::query()->whereIn('action', ['billing_cycle.generated', 'billing_cycle.finalized'])->count())->toBe(2);
    });
});

describe('Current Billing (FR-BIL-014)', function (): void {
    it('shows the empty state, then the cycle with its figures', function (): void {
        Livewire::actingAs($this->cashier)->test(CurrentBilling::class)->assertSee('No month has been billed yet');

        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        Livewire::actingAs($this->cashier)->test(CurrentBilling::class)
            ->assertSee('October 2026')->assertSee('Finalized')->assertSee('₱999.00')->assertSee('Still owed')
            ->assertDontSee('Review and finalize');
    });

    it('offers the review link for a draft month only to someone who can generate', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        Livewire::actingAs($this->owner)->test(CurrentBilling::class)->assertSee('Review and finalize');
        Livewire::actingAs($this->cashier)->test(CurrentBilling::class)->assertDontSee('Review and finalize');
    });
});

describe('Invoices (FR-BIL-008, FR-BIL-014)', function (): void {
    function finalizedOctober(object $test, int $accounts = 2): void
    {
        for ($i = 0; $i < $accounts; $i++) {
            BillingData::activeAccount($test->owner, plan: $test->plan);
        }
        $cycle = app(GenerateBillingCycle::class)->handle($test->owner, '2026-10')->cycle;
        app(FinalizeBillingCycle::class)->handle($test->owner, $cycle->id);
    }

    it('lists numbered invoices with balance and status, and hides drafts', function (): void {
        finalizedOctober($this, 1);
        $draftAccount = BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::invoice($draftAccount, ['billing_period' => '2026-11-01', 'invoice_date' => '2026-11-01']);

        $invoice = Invoice::query()->where('status', 'UNPAID')->sole();

        Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->assertSee($invoice->invoice_no)->assertSee('₱999.00')->assertSee('Unpaid')
            ->assertDontSee('Draft');
    });

    it('searches by invoice number and filters by status and month', function (): void {
        finalizedOctober($this, 2);
        [$first, $second] = Invoice::query()->orderBy('id')->get()->all();

        $component = Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->set('search', $first->invoice_no)->assertSee($first->invoice_no)->assertDontSee($second->invoice_no);

        $component->set('search', '')->set('period', '2026-09')->assertSee('No invoices match');
        $component->set('period', '2026-10')->assertSee($second->invoice_no);
        $component->set('status', 'PAID')->assertSee('No invoices match');
    });

    it('derives OVERDUE from the due date and the grace setting, with no data change', function (): void {
        finalizedOctober($this, 1);
        $invoice = Invoice::query()->sole();
        expect($invoice->due_date->toDateString())->toBe('2026-10-05');

        // Today is Oct 9 and grace is 0: 4 days past due.
        Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->assertSee('Overdue')->assertSee('4 days past due')
            ->set('status', 'OVERDUE')->assertSee($invoice->invoice_no);

        // Raising the grace period past today clears OVERDUE without touching any row.
        app(UpdateSetting::class)->handle($this->owner, 'billing.grace_days', 10, 'Policy');

        Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->set('status', 'OVERDUE')->assertSee('No invoices match');
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    });

    it('shows the balance after an approved adjustment', function (): void {
        finalizedOctober($this, 1);
        $invoice = Invoice::query()->sole();
        $admin = Users::make('admin', RoleCode::Administrator);
        $request = app(CreateAdjustment::class)->handle($admin, $invoice->id, AdjustmentKind::Credit, '100.00', 'Promo');
        app(ApproveAdjustment::class)->handle($this->owner, $request->id);

        Livewire::actingAs($this->cashier)->test(Invoices::class)->assertSee('₱999.00')->assertSee('₱899.00');
    });

    it('limits the list to one subscriber', function (): void {
        finalizedOctober($this, 2);
        [$first, $second] = Invoice::query()->orderBy('id')->get()->all();

        Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->set('subscriber', (string) $first->subscriber_id)
            ->assertSee($first->invoice_no)->assertDontSee($second->invoice_no);
    });
});
