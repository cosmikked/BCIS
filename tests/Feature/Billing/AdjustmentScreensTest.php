<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Livewire\Billing\Adjustments;
use App\Livewire\Billing\InvoiceDetail;
use App\Models\Adjustment;
use App\Models\LedgerEntry;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-009, FR-BIL-013, FR-BIL-014, PRD 19 #8, INV-17, TO-08

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);
    $this->technician = Users::make('tech', RoleCode::Technician);

    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $this->account = BillingData::activeAccount($this->owner, plan: $plan);
    $this->invoice = BillingData::postedInvoice($this->account, $this->owner->id);
});

describe('who may open the pages (INV-17)', function (): void {
    it('shows an invoice to billing.view and the queue only to invoice.adjust', function (): void {
        $show = route('billing.invoices.show', $this->invoice->id);

        $this->actingAs($this->cashier)->get($show)->assertOk();
        $this->actingAs($this->technician)->get($show)->assertForbidden();

        $this->actingAs($this->cashier)->get(route('billing.adjustments'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('billing.adjustments'))->assertOk();
        $this->actingAs($this->owner)->get(route('billing.adjustments'))->assertOk();
    });

    it('returns 404 for an invoice that does not exist', function (): void {
        $this->actingAs($this->owner)->get(route('billing.invoices.show', 999999))->assertNotFound();
    });
});

describe('invoice detail (FR-BIL-014)', function (): void {
    it('shows the balance made of total, approved adjustments and payments', function (): void {
        $credit = app(CreateAdjustment::class)->handle($this->admin, $this->invoice->id, AdjustmentKind::Credit, '100.00', 'Promo');
        app(ApproveAdjustment::class)->handle($this->owner, $credit->id);

        Livewire::actingAs($this->cashier)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->assertSee($this->invoice->invoice_no)
            ->assertSee('₱999.00')->assertSee('-₱100.00')->assertSee('₱899.00')
            ->assertSee($credit->adjustment_no)->assertSee('Approved');
    });

    it('shows the ledger lines only to someone who may read the ledger', function (): void {
        Livewire::actingAs($this->owner)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->assertSee('Ledger postings for this invoice');
        Livewire::actingAs($this->cashier)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->assertDontSee('Ledger postings for this invoice');
    });

    it('offers the request form only to a user who can request, and never on a void invoice', function (): void {
        Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])->assertSee('Request a correction');
        Livewire::actingAs($this->cashier)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])->assertDontSee('Request a correction');

        $void = BillingData::postedInvoice($this->account, $this->owner->id, 5000, ['status' => 'VOID', 'voided_at' => now(), 'void_reason' => 'Test']);
        Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $void->id])
            ->assertDontSee('Request a correction')->assertSee('This invoice is void');
    });

    it('requests a credit, which stays PENDING and changes no balance', function (): void {
        Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->set('kind', 'CREDIT')->set('amount', '250.00')->set('reason', 'Promo not applied')
            ->call('requestAdjustment')->assertHasNoErrors()
            ->assertSee('requested. It changes nothing until another authorized user approves it')
            ->assertSee('Waiting for approval');

        $adjustment = Adjustment::query()->sole();
        expect($adjustment->status)->toBe(AdjustmentStatus::Pending)
            ->and($adjustment->amount_centavos->centavos)->toBe(25000)
            ->and($adjustment->requested_by)->toBe($this->admin->id)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('requests a void for the whole balance', function (): void {
        Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->set('kind', 'VOID')->assertSee('Voids the whole outstanding balance of')
            ->set('reason', 'Billed in error')->call('requestAdjustment')->assertHasNoErrors();

        expect(Adjustment::query()->sole()->amount_centavos->centavos)->toBe(99900);
    });

    it('shows the Action\'s errors beside the form', function (): void {
        Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->set('kind', 'CREDIT')->set('amount', '5000.00')->set('reason', 'Too much')
            ->call('requestAdjustment')->assertHasErrors(['amount'])
            ->set('amount', '10.00')->set('reason', ' ')->call('requestAdjustment')->assertHasErrors(['reason'])
            ->set('kind', 'BOGUS')->call('requestAdjustment')->assertHasErrors(['kind']);

        expect(Adjustment::query()->count())->toBe(0);
    });

    it('refuses a request from a user without the permission, even if the form is forged', function (): void {
        Livewire::actingAs($this->cashier)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id])
            ->set('kind', 'CREDIT')->set('amount', '10.00')->set('reason', 'Because')
            ->call('requestAdjustment')->assertForbidden();

        expect(Adjustment::query()->count())->toBe(0);
    });

    it('keeps the invoice id locked', function (): void {
        $component = Livewire::actingAs($this->admin)->test(InvoiceDetail::class, ['invoice' => $this->invoice->id]);

        expect(fn () => $component->set('invoiceId', 1))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

describe('Adjustments queue (maker-checker)', function (): void {
    function pendingCredit(object $test, string $amount = '250.00'): Adjustment
    {
        return app(CreateAdjustment::class)->handle($test->admin, $test->invoice->id, AdjustmentKind::Credit, $amount, 'Promo not applied');
    }

    it('lists pending requests with who asked', function (): void {
        $adjustment = pendingCredit($this);

        Livewire::actingAs($this->owner)->test(Adjustments::class)
            ->assertSee('Waiting for a decision (1)')->assertSee($adjustment->adjustment_no)
            ->assertSee($this->invoice->invoice_no)->assertSee('Test admin');
    });

    it('does not offer the requester the buttons for their own request', function (): void {
        $adjustment = pendingCredit($this);

        Livewire::actingAs($this->admin)->test(Adjustments::class)
            ->call('select', $adjustment->id)
            ->assertSee('You asked for this')->assertDontSee('wire:click="approve"', false);
    });

    it('lets another user approve, which posts the ledger entry', function (): void {
        $adjustment = pendingCredit($this);

        Livewire::actingAs($this->owner)->test(Adjustments::class)
            ->call('select', $adjustment->id)->assertSee('Balance now')->assertSee('₱999.00')
            ->set('note', 'Checked')->call('approve')->assertHasNoErrors()
            ->assertSee('approved and posted to the ledger')->assertSee('Waiting for a decision (0)');

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Approved)
            ->and(LedgerEntry::query()->where('entry_type', 'ADJUSTMENT')->sole()->credit_centavos->centavos)->toBe(25000);
    });

    it('refuses to let the requester approve even if the call is forced', function (): void {
        $adjustment = pendingCredit($this);

        Livewire::actingAs($this->admin)->test(Adjustments::class)
            ->call('select', $adjustment->id)->call('approve')->assertHasErrors(['adjustment']);

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('rejects with a note and posts nothing, and needs the note', function (): void {
        $adjustment = pendingCredit($this);

        $component = Livewire::actingAs($this->owner)->test(Adjustments::class)
            ->call('select', $adjustment->id)->call('reject')->assertHasErrors(['reason']);

        $component->set('note', 'No evidence of the promo')->call('reject')->assertHasNoErrors()->assertSee('rejected. Nothing was posted');

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Rejected)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    });

    it('shows a stale approval as an error and leaves the request pending', function (): void {
        $first = pendingCredit($this, '600.00');
        $second = pendingCredit($this, '600.00');
        app(ApproveAdjustment::class)->handle($this->owner, $first->id);

        Livewire::actingAs($this->owner)->test(Adjustments::class)
            ->call('select', $second->id)->call('approve')->assertHasErrors(['amount']);

        expect($second->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    it('approves a void, which makes the invoice VOID', function (): void {
        $void = app(CreateAdjustment::class)->handle($this->admin, $this->invoice->id, AdjustmentKind::Void, null, 'Billed in error');

        Livewire::actingAs($this->owner)->test(Adjustments::class)
            ->call('select', $void->id)->call('approve')->assertHasNoErrors();

        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Void);
    });

    it('refuses the queue to a user without invoice.adjust and locks the selected id', function (): void {
        Livewire::actingAs($this->cashier)->test(Adjustments::class)->assertForbidden();

        $component = Livewire::actingAs($this->owner)->test(Adjustments::class);
        expect(fn () => $component->set('selectedId', 1))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('cannot be pointed at a request that does not exist', function (): void {
        Livewire::actingAs($this->owner)->test(Adjustments::class)->call('select', 999999)->assertNotFound();
    });
});
