<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Models\Adjustment;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingData;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-BIL-004, FR-BIL-009, INV-02, INV-05
//
// The model hooks are only the first guard (the triggers in DatabaseGuardsTest
// are the real ones), but they must give a readable error before the database does.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->account = SubscriberData::account($this->owner);
});

it('casts money, dates and statuses to value objects', function (): void {
    $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);
    $invoice = Invoice::query()->findOrFail($id);

    expect($invoice->total_centavos)->toBeInstanceOf(Money::class)
        ->and($invoice->total_centavos->centavos)->toBe(99900)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->due_date->toDateString())->toBe('2026-10-05');
});

describe('invoices', function (): void {
    it('lets a draft change and be deleted', function (): void {
        $invoice = Invoice::query()->findOrFail(BillingData::invoice($this->account));

        $invoice->update(['total_centavos' => Money::fromCentavos(50000)]);
        $invoice->delete();

        expect(Invoice::query()->count())->toBe(0);
    });

    it('refuses to change the money, dates or number of a finalized invoice', function (string $column, mixed $value): void {
        $invoice = Invoice::query()->findOrFail(BillingData::invoice($this->account, ['status' => 'UNPAID']));

        expect(fn () => $invoice->update([$column => $value]))->toThrow(LogicException::class, 'finalized invoice cannot be changed');
    })->with([
        'total' => ['total_centavos', Money::fromCentavos(1)],
        'due date' => ['due_date', '2026-12-31'],
        'invoice number' => ['invoice_no', 'INV-999999'],
    ]);

    it('refuses to delete a finalized invoice', function (): void {
        $invoice = Invoice::query()->findOrFail(BillingData::invoice($this->account, ['status' => 'UNPAID']));

        expect(fn () => $invoice->delete())->toThrow(LogicException::class);
    });

    it('still lets the status follow the balance', function (): void {
        $invoice = Invoice::query()->findOrFail(BillingData::invoice($this->account, ['status' => 'UNPAID']));

        $invoice->update(['status' => InvoiceStatus::PartiallyPaid]);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid);
    });
});

describe('invoice items', function (): void {
    it('allows changes while the invoice is a draft', function (): void {
        $draft = BillingData::invoice($this->account);
        $item = InvoiceItem::query()->findOrFail(BillingData::item($draft));

        $item->update(['amount_centavos' => Money::fromCentavos(1000)]);
        $item->delete();

        expect(InvoiceItem::query()->count())->toBe(0);
    });

    it('refuses to add, change or remove items of a finalized invoice', function (): void {
        $draft = BillingData::invoice($this->account);
        $item = InvoiceItem::query()->findOrFail(BillingData::item($draft));
        Invoice::query()->whereKey($draft)->toBase()->update(['status' => 'UNPAID', 'invoice_no' => 'INV-000900', 'finalized_at' => now()]);

        expect(fn () => $item->update(['description' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => $item->delete())->toThrow(LogicException::class)
            ->and(fn () => InvoiceItem::query()->create(['invoice_id' => $draft, 'item_type' => 'OTHER', 'description' => 'late', 'amount_centavos' => Money::fromCentavos(100)]))
            ->toThrow(LogicException::class);
    });
});

describe('billing cycles', function (): void {
    it('is never deleted, and a finalized one is never edited', function (): void {
        $cycle = BillingCycle::query()->findOrFail(BillingData::cycle('2026-10-01'));

        expect(fn () => $cycle->delete())->toThrow(LogicException::class);

        $cycle->update(['status' => BillingCycleStatus::Finalized, 'finalized_by' => $this->owner->id, 'finalized_at' => now()]);

        expect(fn () => $cycle->update(['invoice_count' => 3]))->toThrow(LogicException::class);
    });
});

describe('adjustments', function (): void {
    it('can be decided once, then never changed or deleted', function (): void {
        $invoice = BillingData::invoice($this->account, ['status' => 'UNPAID']);
        $adjustment = Adjustment::query()->findOrFail(BillingData::adjustment($this->account, $invoice, $this->admin->id));

        $adjustment->update(['status' => AdjustmentStatus::Approved, 'approved_by' => $this->owner->id, 'decided_at' => now()]);

        expect(fn () => $adjustment->update(['decision_note' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => $adjustment->delete())->toThrow(LogicException::class);
    });
});
