<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\InvoiceNumberSource;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Livewire\Billing\Invoices;
use App\Livewire\Subscribers\SubscriberProfile;
use App\Models\Invoice;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-014, FR-BIL-016, FR-SUB-003, FR-SUB-009, INV-17, TO-05

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);

    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $this->account = BillingData::activeAccount($this->owner, plan: $plan);
    $this->other = BillingData::activeAccount($this->owner, plan: $plan);

    $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
    app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

    $this->invoice = Invoice::query()->where('service_account_id', $this->account->id)->sole();
    $this->otherInvoice = Invoice::query()->where('service_account_id', $this->other->id)->sole();
});

function profile(object $test, $user, string $tab)
{
    return Livewire::actingAs($user)->test(SubscriberProfile::class, ['subscriber' => $test->account->subscriber_id])->call('setTab', $tab);
}

describe('Billing tab (FR-BIL-014)', function (): void {
    it('lists only this subscriber\'s invoices with balance, due date and status', function (): void {
        profile($this, $this->cashier, 'billing')->assertOk()
            ->assertSee($this->invoice->invoice_no)->assertSee('₱999.00')->assertSee('Oct 5, 2026')
            ->assertSee('Overdue')
            ->assertDontSee($this->otherInvoice->invoice_no);
    });

    it('does not show drafts', function (): void {
        $draftAccount = BillingData::activeAccount($this->owner);
        $draft = Invoice::query()->findOrFail(BillingData::invoice($draftAccount, ['billing_period' => '2026-11-01', 'invoice_date' => '2026-11-01']));

        Livewire::actingAs($this->owner)->test(SubscriberProfile::class, ['subscriber' => $draftAccount->subscriber_id])
            ->call('setTab', 'billing')->assertSee('No invoices yet')->assertDontSee('Draft');
        expect($draft->invoice_no)->toBeNull();
    });

    it('shows the empty state for a subscriber with no invoices', function (): void {
        // Activated after the month was finalized, so it has no invoice yet.
        $fresh = BillingData::activeAccount($this->owner);

        Livewire::actingAs($this->owner)->test(SubscriberProfile::class, ['subscriber' => $fresh->subscriber_id])
            ->call('setTab', 'billing')->assertSee('No invoices yet');
    });
});

describe('Ledger tab (FR-BIL-016, TO-05)', function (): void {
    it('shows the running balance, the amount owed now and the closing balance', function (): void {
        $credit = app(CreateAdjustment::class)->handle($this->admin, $this->invoice->id, AdjustmentKind::Credit, '100.00', 'Promo');
        app(ApproveAdjustment::class)->handle($this->owner, $credit->id);

        profile($this, $this->owner, 'ledger')->assertOk()
            ->assertSee('Owed now')->assertSee('₱899.00')
            ->assertSee('Invoice '.$this->invoice->invoice_no)->assertSee($credit->adjustment_no)
            ->assertSee('₱999.00')
            ->assertDontSee('No ledger entries yet');
    });

    it('is hidden from a role without receivable.view and falls back to Overview', function (): void {
        profile($this, $this->cashier, 'ledger')->assertViewHas('activeTab', 'overview')->assertDontSee('Owed now');
    });

    it('filters by date and keeps the true running balance, with the balance before the range', function (): void {
        $credit = app(CreateAdjustment::class)->handle($this->admin, $this->invoice->id, AdjustmentKind::Credit, '100.00', 'Promo');
        app(ApproveAdjustment::class)->handle($this->owner, $credit->id);
        // The invoice is dated 2026-10-01; the credit was posted on 2026-10-09.

        profile($this, $this->owner, 'ledger')
            ->set('ledgerFrom', '2026-10-05')->set('ledgerTo', '2026-10-31')
            ->assertSee('Balance before Oct 5, 2026')->assertSee('₱999.00')->assertSee('₱899.00')
            ->assertDontSee('Invoice '.$this->invoice->invoice_no);
    });

    it('ignores impossible or reversed dates and says so', function (): void {
        profile($this, $this->owner, 'ledger')
            ->set('ledgerFrom', '2026-02-30')->assertSee('Enter valid dates')->assertSee('Invoice '.$this->invoice->invoice_no)
            ->set('ledgerFrom', '2026-10-20')->set('ledgerTo', '2026-10-01')->assertSee('Enter valid dates')
            ->call('clearLedgerFilter')->assertDontSee('Enter valid dates');
    });

    it('does not leak another subscriber\'s entries', function (): void {
        profile($this, $this->owner, 'ledger')->assertDontSee($this->otherInvoice->invoice_no);
    });

    it('shows an empty ledger for a subscriber with no entries', function (): void {
        $fresh = BillingData::activeAccount($this->owner);

        Livewire::actingAs($this->owner)->test(SubscriberProfile::class, ['subscriber' => $fresh->subscriber_id])
            ->call('setTab', 'ledger')->assertSee('No ledger entries yet');
    });
});

describe('invoice number search (FR-SUB-003)', function (): void {
    it('finds the subscriber by invoice number, with or without the prefix', function (): void {
        $digits = substr($this->invoice->invoice_no, 4);

        foreach ([$this->invoice->invoice_no, strtolower($this->invoice->invoice_no), $digits] as $term) {
            $hits = app(GlobalSearch::class)->search($this->cashier, $term);

            expect(collect($hits)->pluck('subscriberId')->all())->toContain($this->account->subscriber_id);
        }

        $hit = collect(app(GlobalSearch::class)->search($this->cashier, $this->invoice->invoice_no))->firstWhere('subscriberId', $this->account->subscriber_id);
        expect($hit->matchedOn)->toContain('Invoice number');
    });

    it('only treats number-shaped terms as invoice numbers', function (): void {
        $source = new InvoiceNumberSource;

        expect($source->subscriberIds('Ana Reyes'))->toBeNull()
            ->and($source->subscriberIds('inv'))->toBeNull()
            ->and($source->subscriberIds('9'))->toBeNull()
            ->and($source->subscriberIds('INV-000001'))->not->toBeNull()
            ->and(InvoiceNumberSource::digitsOf('INV-000123'))->toBe('000123');
    });

    it('does not turn a LIKE wildcard into a match-everything search', function (): void {
        expect((new InvoiceNumberSource)->subscriberIds('%%'))->toBeNull()
            ->and((new InvoiceNumberSource)->subscriberIds('INV-%%'))->toBeNull();
    });

    it('makes the Invoices list show just the invoice for a typed number', function (): void {
        Livewire::actingAs($this->cashier)->test(Invoices::class)
            ->set('search', $this->invoice->invoice_no)
            ->assertSee($this->invoice->invoice_no)->assertDontSee($this->otherInvoice->invoice_no);
    });
});
