<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Billing\LedgerQuery;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LedgerEntry;
use App\Models\ServiceAccount;
use Database\Seeders\CollectionSetupSeeder;
use Database\Seeders\DemoBillingSeeder;
use Database\Seeders\DemoSubscriberSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServicePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

// PRD section 18 (F2), FR-BIL-006, FR-BIL-013, FR-BIL-016, INV-02, INV-06, TO-02, TO-05

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-11 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    Users::make('owner', RoleCode::Owner);
    Users::make('demo.admin', RoleCode::Administrator);
    $this->seed([CollectionSetupSeeder::class, ServicePlanSeeder::class, DemoSubscriberSeeder::class, DemoBillingSeeder::class]);
});

function accountNamed(string $name): ServiceAccount
{
    return ServiceAccount::query()->whereHas('subscriber', fn ($q) => $q->where('full_name', $name))->orderBy('id')->firstOrFail();
}

function invoicesOf(string $name)
{
    return Invoice::query()->where('service_account_id', accountNamed($name)->id)->orderBy('billing_period')->get();
}

function demoCheck1(): void
{
    $cycles = BillingCycle::query()->orderBy('period_month')->get();

    expect($cycles->map(fn ($c) => $c->period_month->format('Y-m'))->all())->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($cycles->pluck('status.value')->unique()->all())->toBe(['FINALIZED'])
        ->and($cycles->sum('invoice_count'))->toBe(Invoice::query()->whereNotNull('invoice_no')->count())
        ->and(Invoice::query()->where('status', 'DRAFT')->count())->toBe(0);
}

function demoCheck2(): void
{
    $invoices = invoicesOf('Ana Reyes');
    $items = InvoiceItem::query()->where('invoice_id', $invoices->first()->id)->orderBy('id')->get();

    expect($invoices)->toHaveCount(1)
        ->and($invoices[0]->billing_period->toDateString())->toBe('2026-10-01')
        ->and($invoices[0]->total_centavos->centavos)->toBe(199900)
        ->and($invoices[0]->due_date->toDateString())->toBe('2026-10-05')
        ->and($invoices[0]->status)->toBe(InvoiceStatus::Unpaid)
        ->and($items->pluck('amount_centavos')->map->centavos->all())->toBe([149900, 50000])
        ->and($items->pluck('item_type.value')->all())->toBe(['SUBSCRIPTION', 'INSTALLATION'])
        ->and(accountNamed('Ana Reyes')->installation_fee_billed_at)->not->toBeNull()
        ->and(accountNamed('Ana Reyes')->status)->toBe(ServiceStatus::Active);
}

function demoCheck3(): void
{
    $ben = invoicesOf('Ben Santos');

    expect($ben->pluck('billing_period')->map->format('Y-m')->all())->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($ben->pluck('total_centavos')->map->centavos->unique()->all())->toBe([99900])
        ->and(InvoiceItem::query()->whereIn('invoice_id', $ben->pluck('id'))->where('item_type', 'INSTALLATION')->count())->toBe(0);
}

function demoCheck4(): void
{
    foreach (['Carlo Mendoza' => 299700, 'Dina Flores' => 300000] as $name => $owed) {
        $invoices = invoicesOf($name);

        expect($invoices)->toHaveCount(3)
            ->and($invoices->pluck('status')->unique()->all())->toBe([InvoiceStatus::Unpaid])
            ->and(accountNamed($name)->status)->toBe(ServiceStatus::Suspended)
            ->and(app(LedgerQuery::class)->currentBalance(accountNamed($name)->subscriber_id)->centavos)->toBe($owed);
    }
}

function demoCheck5(): void
{
    // Both start 2026-09-01 and are untouched by the demo corrections, so Phase 5's
    // wrong-subscriber payment story has two clean ₱1,499.00 invoices each.
    foreach (['Elena Cruz', 'Elaine Cruz'] as $name) {
        $invoices = invoicesOf($name);

        expect($invoices->pluck('billing_period')->map->format('Y-m')->all())->toBe(['2026-09', '2026-10'])
            ->and($invoices->pluck('status')->unique()->all())->toBe([InvoiceStatus::Unpaid])
            ->and($invoices->pluck('total_centavos')->map->centavos->unique()->all())->toBe([149900]);
    }
}

function demoCheck6(): void
{
    $named = ['Ana Reyes', 'Ben Santos', 'Carlo Mendoza', 'Dina Flores', 'Elena Cruz', 'Elaine Cruz', 'Gil Ramos'];
    $credit = Adjustment::query()->where('kind', 'CREDIT')->sole();
    $void = Adjustment::query()->where('kind', 'VOID')->sole();

    foreach ([$credit, $void] as $adjustment) {
        expect($adjustment->status->value)->toBe('APPROVED')
            ->and($adjustment->requested_by)->not->toBe($adjustment->approved_by)
            ->and($adjustment->invoice->subscriber->full_name)->not->toBeIn($named)
            ->and($adjustment->invoice->billing_period->format('Y-m'))->toBe('2026-09');
    }

    // The credit lowers one balance; the void keeps its number and zeroes its balance.
    expect($credit->amount_centavos->centavos)->toBe(10000)
        ->and(app(InvoiceBalanceCalculator::class)->balance($credit->invoice)->centavos)->toBe($credit->invoice->total_centavos->centavos - 10000)
        ->and($credit->invoice->status)->toBe(InvoiceStatus::Unpaid);

    $voided = $void->invoice;
    expect($voided->status)->toBe(InvoiceStatus::Void)
        ->and($voided->invoice_no)->toMatch('/^INV-\d{6}$/')
        ->and($voided->void_reason)->toBe('Demo: billed in error')
        ->and(app(InvoiceBalanceCalculator::class)->balance($voided)->centavos)->toBe(0)
        ->and(Adjustment::query()->count())->toBe(2);
}

function demoCheck7(): void
{
    $billed = Invoice::query()->distinct()->pluck('service_account_id');

    expect(ServiceAccount::query()->whereIn('id', $billed)->whereIn('status', ['PENDING', 'TERMINATED'])->count())->toBe(0)
        ->and(ServiceAccount::query()->where('status', 'PENDING')->count())->toBeGreaterThan(0);
}

function demoCheck8(): void
{
    $numbers = Invoice::query()->whereNotNull('invoice_no')->orderBy('invoice_no')->pluck('invoice_no');
    $byMonth = Invoice::query()->whereNotNull('invoice_no')->orderBy('invoice_no')->get()->groupBy(fn ($i) => $i->billing_period->format('Y-m'));

    expect($numbers->unique())->toHaveCount($numbers->count())
        ->and($byMonth['2026-08']->max('invoice_no'))->toBeLessThan($byMonth['2026-09']->min('invoice_no'))
        ->and($byMonth['2026-09']->max('invoice_no'))->toBeLessThan($byMonth['2026-10']->min('invoice_no'));
}

function demoCheck9(): void
{
    foreach (BillingCycle::query()->get() as $cycle) {
        $invoiced = (int) Invoice::query()->where('billing_cycle_id', $cycle->id)->sum('total_centavos');
        $debited = (int) LedgerEntry::query()->where('entry_type', 'INVOICE')
            ->whereIn('reference_id', Invoice::query()->where('billing_cycle_id', $cycle->id)->select('id'))
            ->sum('debit_centavos');

        expect($debited)->toBe($invoiced)->and($cycle->total_centavos->centavos)->toBe($invoiced);
    }

    $debitAdjustments = (int) DB::table('adjustments')->where('status', 'APPROVED')->where('kind', 'DEBIT')->sum('amount_centavos');
    expect((int) LedgerEntry::query()->sum('debit_centavos'))->toBe((int) Invoice::query()->sum('total_centavos') + $debitAdjustments);
}

function demoCheck10(): void
{
    $mismatches = DB::table('invoices')
        ->leftJoin('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
        ->select('invoices.id')
        ->groupBy('invoices.id', 'invoices.total_centavos')
        ->havingRaw('COALESCE(SUM(invoice_items.amount_centavos), 0) <> invoices.total_centavos')
        ->count();

    expect($mismatches)->toBe(0);
}

function demoCheck11(): void
{
    $ledger = app(LedgerQuery::class);
    $balances = app(InvoiceBalanceCalculator::class);
    $subscribers = LedgerEntry::query()->distinct()->pluck('subscriber_id');

    expect($subscribers->count())->toBeGreaterThan(30);

    foreach ($subscribers as $subscriberId) {
        $sum = $ledger->currentBalance($subscriberId)->centavos;
        $figures = $balances->figures(Invoice::query()->where('subscriber_id', $subscriberId)->whereNotNull('invoice_no')->get());
        $invoiceBalances = array_sum(array_map(fn ($f) => $f->balance()->centavos, $figures));

        expect($ledger->lastRunningBalance($subscriberId)?->centavos)->toBe($sum)
            ->and($invoiceBalances)->toBe($sum);
    }
}

function demoCheck12(): void
{
    expect(AuditLog::query()->where('action', AuditAction::BillingCycleGenerated->value)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', AuditAction::BillingCycleFinalized->value)->count())->toBe(3)
        ->and(AuditLog::query()->where('action', AuditAction::InvoiceVoided->value)->count())->toBe(1);
}

function demoCheck13(): void
{
    $before = [Invoice::query()->count(), LedgerEntry::query()->count(), BillingCycle::query()->count()];

    app(DemoBillingSeeder::class)->run();

    expect([Invoice::query()->count(), LedgerEntry::query()->count(), BillingCycle::query()->count()])->toBe($before)
        ->and(Invoice::query()->where('invoice_type', InvoiceType::OneOff->value)->count())->toBe(0);
}

// Seeding takes seconds, so the checks share a few tests instead of re-seeding thirteen times.
it('builds the demo story: the named people, three months, one credit and one void', function (): void {
    demoCheck1();
    demoCheck2();
    demoCheck3();
    demoCheck4();
    demoCheck5();
    demoCheck6();
    demoCheck7();
});

it('foots to the centavo: numbering, TO-02, FR-BIL-006 and TO-05', function (): void {
    demoCheck8();
    demoCheck9();
    demoCheck10();
    demoCheck11();
});

it('audits the work and does nothing when run again', function (): void {
    demoCheck12();
    demoCheck13();
});
