<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\DiscardDraftInvoices;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Contracts\AllocationTotals;
use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\NullCustomerCreditApplier;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-005, FR-BIL-006, FR-BIL-012, FR-BIL-013, INV-05, INV-10, INV-11, TO-02

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);
    $this->plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
});

/** Generates October for the given accounts' owners and returns the DRAFT cycle. */
function draftOctober(object $test): BillingCycle
{
    return app(GenerateBillingCycle::class)->handle($test->owner, '2026-10')->cycle;
}

function nothingFinalized(): void
{
    expect(Invoice::query()->where('status', '<>', 'DRAFT')->count())->toBe(0)
        ->and(Invoice::query()->whereNotNull('invoice_no')->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::BillingCycleFinalized->value)->count())->toBe(0)
        ->and(BillingCycle::query()->where('status', 'FINALIZED')->count())->toBe(0)
        ->and(ServiceAccount::query()->whereNotNull('installation_fee_billed_at')->count())->toBe(0);
}

describe('finalize (FR-BIL-005)', function (): void {
    it('numbers each invoice, sets it UNPAID and posts one ledger debit', function (): void {
        $a = BillingData::activeAccount($this->owner, plan: $this->plan);
        $b = BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        $done = app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);
        $invoices = Invoice::query()->orderBy('id')->get();

        expect($done->status)->toBe(BillingCycleStatus::Finalized)
            ->and($done->finalized_by)->toBe($this->owner->id)
            ->and($done->finalized_at)->not->toBeNull()
            ->and($done->invoice_count)->toBe(2)
            ->and($done->total_centavos->centavos)->toBe(199800)
            ->and($invoices->pluck('status')->unique()->all())->toBe([InvoiceStatus::Unpaid])
            ->and($invoices->pluck('invoice_no')->all())->each->toMatch('/^INV-\d{6}$/')
            ->and($invoices->pluck('invoice_no')->unique())->toHaveCount(2)
            ->and($invoices->every(fn ($i) => $i->finalized_at !== null))->toBeTrue();

        $entries = LedgerEntry::query()->orderBy('id')->get();
        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('entry_type')->unique()->all())->toBe([LedgerEntryType::Invoice])
            ->and($entries[0]->debit_centavos->centavos)->toBe(99900)
            ->and($entries[0]->credit_centavos->centavos)->toBe(0)
            ->and($entries[0]->reference_type)->toBe('invoice')
            ->and($entries[0]->reference_id)->toBe($invoices[0]->id)
            ->and($entries[0]->reference_no)->toBe($invoices[0]->invoice_no)
            ->and($entries[0]->entry_date->toDateString())->toBe('2026-10-01')
            ->and($entries[0]->subscriber_id)->toBe($a->subscriber_id)
            ->and($entries[1]->subscriber_id)->toBe($b->subscriber_id)
            ->and($entries[0]->created_by)->toBe($this->owner->id)
            ->and($entries[0]->description)->toContain($invoices[0]->invoice_no);
    });

    it('numbers invoices in sequence and never reuses a number (INV-10)', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);
        $numbers = Invoice::query()->orderBy('id')->pluck('invoice_no')->map(fn ($n) => (int) substr($n, 4))->all();

        expect($numbers[1])->toBe($numbers[0] + 1);
    });

    it('writes one audit record with the count, total and number range', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        $cycle = draftOctober($this);

        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);
        $log = AuditLog::query()->where('action', AuditAction::BillingCycleFinalized->value)->sole();

        expect($log->actor_user_id)->toBe($this->owner->id)
            ->and($log->entity_id)->toBe($cycle->id)
            ->and($log->new_values['invoice_count'])->toBe(2)
            ->and($log->new_values['total_centavos'])->toBe(99900 * 2 + 50000)
            ->and($log->new_values['first_invoice_no'])->toBe(Invoice::query()->orderBy('id')->value('invoice_no'))
            ->and($log->new_values['last_invoice_no'])->toBe(Invoice::query()->orderByDesc('id')->value('invoice_no'));
    });

    it('marks the installation fee billed so the next month does not charge it again', function (): void {
        $account = BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        $cycle = draftOctober($this);

        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        expect($account->refresh()->installation_fee_billed_at)->not->toBeNull()
            ->and(Invoice::query()->sole()->total_centavos->centavos)->toBe(149900);

        $this->travelTo('2026-11-09 09:00:00');
        app(GenerateBillingCycle::class)->handle($this->owner, '2026-11');

        $november = Invoice::query()->where('billing_period', '2026-11-01')->sole();
        expect($november->total_centavos->centavos)->toBe(99900)
            ->and($november->items)->toHaveCount(1);
    });

    it('leaves a discarded first invoice free to carry the fee when regenerated', function (): void {
        $account = BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        $cycle = draftOctober($this);
        app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Wrong due day');

        expect($account->refresh()->installation_fee_billed_at)->toBeNull();

        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        expect(Invoice::query()->sole()->total_centavos->centavos)->toBe(149900);
    });

    it('is impossible to finalize twice, or to regenerate or discard afterwards (FR-BIL-004)', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);
        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id))->toThrow(ValidationException::class)
            ->and(fn () => app(GenerateBillingCycle::class)->handle($this->owner, '2026-10'))->toThrow(ValidationException::class)
            ->and(fn () => app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Undo'))->toThrow(ValidationException::class)
            ->and(Invoice::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('refuses to finalize a cycle with no drafts', function (): void {
        $cycle = draftOctober($this);

        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id))->toThrow(ValidationException::class);
    });

    it('needs the billing.generate permission', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->cashier, $cycle->id))->toThrow(AuthorizationException::class);
        nothingFinalized();
    });

    it('makes the finalized invoice immutable in the database (FR-BIL-009)', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        app(FinalizeBillingCycle::class)->handle($this->owner, draftOctober($this)->id);
        $invoice = Invoice::query()->sole();

        expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_centavos' => 1])))
            ->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('invoice_items')->where('invoice_id', $invoice->id)->delete()))
            ->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('ledger_entries')->delete()))
            ->toThrow(QueryException::class, 'append-only');
    });
});

describe('all or nothing (INV-11)', function (): void {
    it('leaves no numbered invoice and no ledger row when the credit hook fails part-way', function (): void {
        foreach (range(1, 3) as $i) {
            BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        }
        $cycle = draftOctober($this);

        // Fails on the THIRD invoice, after two were already numbered and posted.
        $this->app->bind(CustomerCreditApplier::class, fn () => new class implements CustomerCreditApplier
        {
            private int $calls = 0;

            public function apply(Invoice $invoice, User $actor): void
            {
                if (++$this->calls === 3) {
                    throw new RuntimeException('forced failure');
                }
            }
        });

        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id))->toThrow(RuntimeException::class, 'forced failure');

        nothingFinalized();
        expect(Invoice::query()->where('status', 'DRAFT')->count())->toBe(3)
            ->and($cycle->refresh()->status)->toBe(BillingCycleStatus::Draft);
    });

    it('leaves nothing behind when the ledger itself rejects a row', function (): void {
        $a = BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        // The second invoice already "has" a ledger debit, so its posting violates the
        // one-ledger-row-per-invoice index after the first invoice went through.
        $second = Invoice::query()->orderByDesc('id')->firstOrFail();
        BillingData::ledger($a, $this->owner->id, ['reference_type' => 'invoice', 'reference_id' => $second->id]);
        $preexisting = LedgerEntry::query()->count();

        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id))->toThrow(QueryException::class);

        expect(LedgerEntry::query()->count())->toBe($preexisting)
            ->and(Invoice::query()->whereNotNull('invoice_no')->count())->toBe(0)
            ->and(Invoice::query()->where('status', 'DRAFT')->count())->toBe(2)
            ->and(AuditLog::query()->where('action', AuditAction::BillingCycleFinalized->value)->count())->toBe(0)
            ->and($cycle->refresh()->status)->toBe(BillingCycleStatus::Draft);
    });

    it('can be finalized after a failure, and the failed numbers stay unused', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        $this->app->bind(CustomerCreditApplier::class, fn () => new class implements CustomerCreditApplier
        {
            public function apply(Invoice $invoice, User $actor): void
            {
                throw new RuntimeException('forced failure');
            }
        });
        expect(fn () => app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id))->toThrow(RuntimeException::class);

        $this->app->bind(CustomerCreditApplier::class, fn () => new NullCustomerCreditApplier);
        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        $numbers = Invoice::query()->orderBy('id')->pluck('invoice_no')->map(fn ($n) => (int) substr($n, 4))->all();

        // The first attempt drew a number and rolled back: a gap, never a reuse (FR-BIL-013).
        expect($numbers)->toHaveCount(2)
            ->and($numbers[0])->toBeGreaterThan(1)
            ->and($numbers[1])->toBe($numbers[0] + 1);
    });
});

describe('customer credit hook (FR-BIL-012)', function (): void {
    it('is called once per invoice, in id order, after numbering and posting and inside the transaction', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        $recorder = new ArrayObject;
        $this->app->bind(CustomerCreditApplier::class, fn () => new class($recorder) implements CustomerCreditApplier
        {
            public function __construct(private readonly ArrayObject $seen) {}

            public function apply(Invoice $invoice, User $actor): void
            {
                $this->seen[] = [
                    'id' => $invoice->id,
                    'numbered' => $invoice->invoice_no !== null,
                    'status' => $invoice->status,
                    'posted' => LedgerEntry::query()->where('reference_id', $invoice->id)->exists(),
                    'in_transaction' => DB::transactionLevel() > 0,
                    'actor' => $actor->id,
                ];
            }
        });

        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);
        $seen = $recorder->getArrayCopy();

        expect($seen)->toHaveCount(2)
            ->and(array_column($seen, 'id'))->toBe(Invoice::query()->orderBy('id')->pluck('id')->all())
            ->and(array_column($seen, 'numbered'))->each->toBeTrue()
            ->and(array_column($seen, 'status'))->each->toBe(InvoiceStatus::Unpaid)
            ->and(array_column($seen, 'posted'))->each->toBeTrue()
            ->and(array_column($seen, 'in_transaction'))->each->toBeTrue()
            ->and(array_column($seen, 'actor'))->each->toBe($this->owner->id);
    });

    it('does nothing in Phase 4: invoices stay UNPAID with their full balance', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        app(FinalizeBillingCycle::class)->handle($this->owner, draftOctober($this)->id);

        expect(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Unpaid);
    });

    it('resolves the status after the hook, so an invoice covered by credit ends PAID', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = draftOctober($this);

        // Stand-in for Phase 5: the subscriber's credit already paid the whole invoice.
        $this->app->bind(AllocationTotals::class, fn () => new class implements AllocationTotals
        {
            public function forInvoices(array $invoiceIds): array
            {
                return array_fill_keys($invoiceIds, Money::fromCentavos(99900));
            }
        });

        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        // It is still numbered, still debited on the ledger: the credit arrived as a payment, not a discount.
        expect(Invoice::query()->sole()->status)->toBe(InvoiceStatus::Paid)
            ->and(LedgerEntry::query()->sole()->debit_centavos->centavos)->toBe(99900);
    });
});

describe('finalized invoices equal ledger debits (TO-02)', function (): void {
    it('ties out for the period, fees included', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        BillingData::activeAccount($this->owner, ['billing_due_day' => 31], $this->plan);
        app(FinalizeBillingCycle::class)->handle($this->owner, draftOctober($this)->id);

        $invoiced = (int) Invoice::query()->where('billing_period', '2026-10-01')->where('status', '<>', 'DRAFT')->sum('total_centavos');
        $debited = (int) LedgerEntry::query()->where('entry_type', 'INVOICE')->whereBetween('entry_date', ['2026-10-01', '2026-10-31'])->sum('debit_centavos');

        expect($invoiced)->toBe(99900 * 3 + 50000)
            ->and($debited)->toBe($invoiced);
    });

    it('gives every finalized invoice exactly one ledger debit of its own amount', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        app(FinalizeBillingCycle::class)->handle($this->owner, draftOctober($this)->id);

        $mismatches = DB::select(<<<'SQL'
            SELECT i.id FROM invoices i
            LEFT JOIN ledger_entries l ON l.entry_type = 'INVOICE' AND l.reference_id = i.id
            WHERE i.status <> 'DRAFT' AND (l.id IS NULL OR l.debit_centavos <> i.total_centavos OR l.subscriber_id <> i.subscriber_id)
            SQL);

        expect($mismatches)->toBe([]);
    });
});
