<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\AllocationTotals;
use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\InvoiceFigures;
use App\Domain\Billing\InvoiceStatusResolver;
use App\Domain\Billing\NullAllocationTotals;
use App\Domain\Billing\NullCustomerCreditApplier;
use App\Domain\Money\Money;
use App\Domain\Security\Actions\UpdateSetting;
use App\Domain\Security\RoleCode;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingData;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-BIL-008, FR-BIL-012, FR-BIL-016, INV-06

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->account = SubscriberData::account($this->owner);
    $this->invoiceId = BillingData::invoice($this->account, ['status' => 'UNPAID']);
});

function balanceOf(int $invoiceId): int
{
    return app(InvoiceBalanceCalculator::class)->balance(Invoice::query()->findOrFail($invoiceId))->centavos;
}

describe('invoice balance (INV-06)', function (): void {
    it('is the total when nothing else touched the invoice', function (): void {
        expect(balanceOf($this->invoiceId))->toBe(99900);
    });

    it('counts only APPROVED adjustments', function (): void {
        BillingData::adjustment($this->account, $this->invoiceId, $this->admin->id, ['amount_centavos' => 10000]);
        BillingData::adjustment($this->account, $this->invoiceId, $this->admin->id, [
            'amount_centavos' => 20000, 'status' => 'REJECTED', 'approved_by' => $this->owner->id,
            'decided_at' => now(), 'decision_note' => 'Not justified',
        ]);

        expect(balanceOf($this->invoiceId))->toBe(99900);
    });

    it('subtracts credits, write-offs and voids and adds debits', function (): void {
        BillingData::approvedAdjustment($this->account, $this->invoiceId, $this->admin->id, $this->owner->id, 'CREDIT', 10000);
        expect(balanceOf($this->invoiceId))->toBe(89900);

        BillingData::approvedAdjustment($this->account, $this->invoiceId, $this->admin->id, $this->owner->id, 'DEBIT', 2500);
        expect(balanceOf($this->invoiceId))->toBe(92400);

        BillingData::approvedAdjustment($this->account, $this->invoiceId, $this->admin->id, $this->owner->id, 'WRITE_OFF', 400);
        expect(balanceOf($this->invoiceId))->toBe(92000);
    });

    it('reaches zero when a void takes the whole outstanding amount', function (): void {
        BillingData::approvedAdjustment($this->account, $this->invoiceId, $this->admin->id, $this->owner->id, 'VOID', 99900);

        expect(balanceOf($this->invoiceId))->toBe(0);
    });

    it('reads many invoices in one go and defaults to zero adjustments', function (): void {
        $other = SubscriberData::account($this->owner);
        $second = BillingData::invoice($other, ['status' => 'UNPAID', 'total_centavos' => 50000]);
        BillingData::approvedAdjustment($other, $second, $this->admin->id, $this->owner->id, 'CREDIT', 5000);

        $figures = app(InvoiceBalanceCalculator::class)->figures(Invoice::query()->whereIn('id', [$this->invoiceId, $second])->get());

        expect($figures)->toHaveCount(2)
            ->and($figures[$this->invoiceId]->balance()->centavos)->toBe(99900)
            ->and($figures[$second]->balance()->centavos)->toBe(45000)
            ->and($figures[$second]->adjustments->centavos)->toBe(-5000);
        expect(app(InvoiceBalanceCalculator::class)->figures([]))->toBe([]);
    });

    it('subtracts what the allocation source reports (Phase 5 plugs in here)', function (): void {
        $this->app->bind(AllocationTotals::class, fn () => new class implements AllocationTotals
        {
            public function forInvoices(array $invoiceIds): array
            {
                return array_fill_keys($invoiceIds, Money::fromCentavos(30000));
            }
        });

        expect(balanceOf($this->invoiceId))->toBe(69900);
    });

    it('has nothing allocated until payments exist', function (): void {
        expect(app(AllocationTotals::class))->toBeInstanceOf(NullAllocationTotals::class);
    });
});

describe('stored status follows the balance (FR-BIL-008)', function (): void {
    it('resolves each state', function (InvoiceStatus $current, int $total, int $adjustments, int $allocated, InvoiceStatus $expected): void {
        $figures = new InvoiceFigures(Money::fromCentavos($total), Money::fromCentavos($adjustments), Money::fromCentavos($allocated));

        expect(app(InvoiceStatusResolver::class)->statusFor($current, $figures))->toBe($expected);
    })->with([
        'untouched' => [InvoiceStatus::Unpaid, 100000, 0, 0, InvoiceStatus::Unpaid],
        'part paid' => [InvoiceStatus::Unpaid, 100000, 0, 40000, InvoiceStatus::PartiallyPaid],
        'fully paid' => [InvoiceStatus::PartiallyPaid, 100000, 0, 100000, InvoiceStatus::Paid],
        'paid after a credit' => [InvoiceStatus::Unpaid, 100000, -10000, 90000, InvoiceStatus::Paid],
        'wiped out by a credit, no payment' => [InvoiceStatus::Unpaid, 100000, -100000, 0, InvoiceStatus::Credited],
        'debit after full payment' => [InvoiceStatus::Paid, 100000, 5000, 100000, InvoiceStatus::PartiallyPaid],
        'credit only, balance remains' => [InvoiceStatus::Unpaid, 100000, -10000, 0, InvoiceStatus::Unpaid],
        'a draft stays a draft' => [InvoiceStatus::Draft, 100000, 0, 0, InvoiceStatus::Draft],
        'a void stays void' => [InvoiceStatus::Void, 100000, -100000, 0, InvoiceStatus::Void],
    ]);
});

describe('OVERDUE is derived, never stored (FR-BIL-008, FR-REC-006)', function (): void {
    function overdue(string $today, string $due = '2026-10-05', int $balance = 99900, InvoiceStatus $status = InvoiceStatus::Unpaid): bool
    {
        return app(InvoiceStatusResolver::class)->isOverdue($status, CarbonImmutable::parse($due), Money::fromCentavos($balance), CarbonImmutable::parse($today));
    }

    it('is not overdue on the due date, and is the day after with no grace', function (): void {
        expect(overdue('2026-10-05'))->toBeFalse()
            ->and(overdue('2026-10-06'))->toBeTrue();
    });

    it('moves with the grace setting and needs no data rewrite', function (): void {
        expect(overdue('2026-10-08'))->toBeTrue();

        app(UpdateSetting::class)->handle($this->owner, 'billing.grace_days', 3, 'Three days of grace');

        expect(overdue('2026-10-08'))->toBeFalse()
            ->and(overdue('2026-10-09'))->toBeTrue();
    });

    it('is never overdue once nothing is owed, or when not collectible', function (): void {
        expect(overdue('2026-12-01', balance: 0))->toBeFalse()
            ->and(overdue('2026-12-01', status: InvoiceStatus::Paid))->toBeFalse()
            ->and(overdue('2026-12-01', status: InvoiceStatus::Void))->toBeFalse()
            ->and(overdue('2026-12-01', status: InvoiceStatus::Draft))->toBeFalse()
            ->and(overdue('2026-12-01', status: InvoiceStatus::Credited))->toBeFalse();
    });

    it('is overdue while partially paid', function (): void {
        expect(overdue('2026-10-20', status: InvoiceStatus::PartiallyPaid))->toBeTrue();
    });

    it('counts whole days past the due date', function (): void {
        $resolver = app(InvoiceStatusResolver::class);
        $due = CarbonImmutable::parse('2026-10-05');

        expect($resolver->daysPastDue($due, CarbonImmutable::parse('2026-10-05')))->toBe(0)
            ->and($resolver->daysPastDue($due, CarbonImmutable::parse('2026-10-04')))->toBe(0)
            ->and($resolver->daysPastDue($due, CarbonImmutable::parse('2026-10-15')))->toBe(10)
            ->and($resolver->daysPastDue($due, CarbonImmutable::parse('2026-12-16')))->toBe(72);
    });

    it('has no OVERDUE value to store', function (): void {
        expect(array_map(fn (InvoiceStatus $s) => $s->value, InvoiceStatus::cases()))->not->toContain('OVERDUE');
    });
});

describe('credit hook for Phase 5 (FR-BIL-012)', function (): void {
    it('is bound to an implementation that does nothing yet', function (): void {
        $applier = app(CustomerCreditApplier::class);
        $invoice = Invoice::query()->findOrFail($this->invoiceId);

        expect($applier)->toBeInstanceOf(NullCustomerCreditApplier::class);

        $applier->apply($invoice, $this->owner);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
            ->and(balanceOf($this->invoiceId))->toBe(99900);
    });
});
