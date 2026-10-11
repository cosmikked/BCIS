<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Actions\RejectAdjustment;
use App\Domain\Billing\Contracts\AllocationTotals;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Billing\Enums\AdjustmentStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\InvoiceBalanceCalculator;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-009, FR-BIL-013, INV-02, INV-06, INV-10, PRD 19 #8 (maker-checker)

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);

    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $this->account = BillingData::activeAccount($this->owner, plan: $plan);
    // ₱999.00 owed, one debit on the ledger.
    $this->invoice = BillingData::postedInvoice($this->account, $this->owner->id);
});

/** What the subscriber owes according to the ledger: debits minus credits. */
function ledgerBalanceOf(ServiceAccount $account): int
{
    return (int) LedgerEntry::query()->where('subscriber_id', $account->subscriber_id)
        ->selectRaw('COALESCE(SUM(debit_centavos - credit_centavos), 0) AS balance')
        ->toBase()->value('balance');
}

function adjustedBalanceOf(Invoice $invoice): int
{
    return app(InvoiceBalanceCalculator::class)->balance($invoice->fresh())->centavos;
}

function askFor(object $test, AdjustmentKind $kind, ?string $amount, string $reason = 'Promo not applied'): Adjustment
{
    return app(CreateAdjustment::class)->handle($test->admin, $test->invoice->id, $kind, $amount, $reason);
}

describe('requesting (FR-BIL-009)', function (): void {
    it('saves a numbered PENDING request that changes no balance and no ledger', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        expect($adjustment->adjustment_no)->toMatch('/^ADJ-\d{6}$/')
            ->and($adjustment->status)->toBe(AdjustmentStatus::Pending)
            ->and($adjustment->kind)->toBe(AdjustmentKind::Credit)
            ->and($adjustment->amount_centavos->centavos)->toBe(25000)
            ->and($adjustment->requested_by)->toBe($this->admin->id)
            ->and($adjustment->approved_by)->toBeNull()
            ->and($adjustment->invoice_id)->toBe($this->invoice->id)
            ->and($adjustment->subscriber_id)->toBe($this->invoice->subscriber_id)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and(adjustedBalanceOf($this->invoice))->toBe(99900)
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

        $log = AuditLog::query()->where('action', AuditAction::AdjustmentRequested->value)->sole();
        expect($log->reason)->toBe('Promo not applied')
            ->and($log->new_values['amount_centavos'])->toBe(25000);
    });

    it('gives each request the next number from the sequence', function (): void {
        $first = askFor($this, AdjustmentKind::Credit, '10.00');
        $second = askFor($this, AdjustmentKind::Debit, '10.00');

        expect((int) substr($second->adjustment_no, 4))->toBeGreaterThan((int) substr($first->adjustment_no, 4));
    });

    it('needs a written reason', function (): void {
        expect(fn () => askFor($this, AdjustmentKind::Credit, '10.00', ' '))->toThrow(ValidationException::class)
            ->and(Adjustment::query()->count())->toBe(0);
    });

    it('is refused for a user without the permission', function (): void {
        foreach ([AdjustmentKind::Credit, AdjustmentKind::Debit, AdjustmentKind::WriteOff, AdjustmentKind::Void] as $kind) {
            expect(fn () => app(CreateAdjustment::class)->handle($this->cashier, $this->invoice->id, $kind, '10.00', 'Because'))
                ->toThrow(AuthorizationException::class);
        }
        expect(Adjustment::query()->count())->toBe(0);
    });

    it('refuses a credit or write-off larger than the balance (INV-06) but allows exactly the balance', function (): void {
        foreach ([AdjustmentKind::Credit, AdjustmentKind::WriteOff] as $kind) {
            expect(fn () => askFor($this, $kind, '999.01'))->toThrow(ValidationException::class);
        }

        expect(askFor($this, AdjustmentKind::WriteOff, '999.00')->amount_centavos->centavos)->toBe(99900);
    });

    it('allows a debit of any positive size', function (): void {
        expect(askFor($this, AdjustmentKind::Debit, '5000.00')->amount_centavos->centavos)->toBe(500000);
    });

    it('refuses zero, negative and malformed amounts', function (string $amount): void {
        expect(fn () => askFor($this, AdjustmentKind::Credit, $amount))->toThrow(ValidationException::class)
            ->and(Adjustment::query()->count())->toBe(0);
    })->with(['0', '0.00', '-5.00', 'abc', '10.999', '']);

    it('refuses a draft invoice and a void invoice', function (): void {
        $draft = Invoice::query()->findOrFail(BillingData::invoice($this->account, ['billing_period' => '2026-11-01', 'invoice_date' => '2026-11-01']));
        expect(fn () => app(CreateAdjustment::class)->handle($this->admin, $draft->id, AdjustmentKind::Credit, '10.00', 'Because'))
            ->toThrow(ValidationException::class);

        $void = BillingData::postedInvoice($this->account, $this->owner->id, 5000, [
            'status' => 'VOID', 'voided_at' => now(), 'void_reason' => 'Test',
        ]);
        expect(fn () => app(CreateAdjustment::class)->handle($this->admin, $void->id, AdjustmentKind::Credit, '10.00', 'Because'))
            ->toThrow(ValidationException::class);
    });

    it('sets the amount of a void to the whole balance and ignores a typed amount', function (): void {
        $void = askFor($this, AdjustmentKind::Void, '1.00', 'Billed in error');

        expect($void->amount_centavos->centavos)->toBe(99900)
            ->and($void->kind)->toBe(AdjustmentKind::Void);
    });

    it('allows only one pending void per invoice', function (): void {
        askFor($this, AdjustmentKind::Void, null, 'Billed in error');

        expect(fn () => askFor($this, AdjustmentKind::Void, null, 'Again'))->toThrow(ValidationException::class)
            ->and(Adjustment::query()->count())->toBe(1);
    });
});

describe('approving (maker-checker, INV-02)', function (): void {
    it('posts a ledger credit for a credit adjustment and lowers the balance', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        $done = app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id, 'Checked with the subscriber');

        expect($done->status)->toBe(AdjustmentStatus::Approved)
            ->and($done->approved_by)->toBe($this->owner->id)
            ->and($done->decided_at)->not->toBeNull()
            ->and($done->decision_note)->toBe('Checked with the subscriber')
            ->and(adjustedBalanceOf($this->invoice))->toBe(74900)
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);

        $entry = LedgerEntry::query()->where('entry_type', LedgerEntryType::Adjustment->value)->sole();
        expect($entry->credit_centavos->centavos)->toBe(25000)
            ->and($entry->debit_centavos->centavos)->toBe(0)
            ->and($entry->entry_date->toDateString())->toBe('2026-10-09')
            ->and($entry->reference_type)->toBe('adjustment')
            ->and($entry->reference_id)->toBe($adjustment->id)
            ->and($entry->reference_no)->toBe($adjustment->adjustment_no)
            ->and($entry->subscriber_id)->toBe($this->account->subscriber_id)
            ->and($entry->created_by)->toBe($this->owner->id)
            ->and(ledgerBalanceOf($this->account))->toBe(74900);

        $log = AuditLog::query()->where('action', AuditAction::AdjustmentApproved->value)->sole();
        expect($log->actor_user_id)->toBe($this->owner->id)
            ->and($log->new_values['requested_by'])->toBe($this->admin->id);
    });

    it('posts a ledger debit for a debit adjustment and raises the balance', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Debit, '100.00', 'Late fee agreed');

        app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id);

        $entry = LedgerEntry::query()->where('entry_type', 'ADJUSTMENT')->sole();
        expect($entry->debit_centavos->centavos)->toBe(10000)
            ->and($entry->credit_centavos->centavos)->toBe(0)
            ->and(adjustedBalanceOf($this->invoice))->toBe(109900)
            ->and(ledgerBalanceOf($this->account))->toBe(109900);
    });

    it('marks the invoice CREDITED when a write-off clears the balance with no payment', function (): void {
        $adjustment = askFor($this, AdjustmentKind::WriteOff, '999.00', 'Uncollectible');

        app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id);

        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
            ->and(adjustedBalanceOf($this->invoice))->toBe(0)
            ->and(ledgerBalanceOf($this->account))->toBe(0);
    });

    it('does not let the requester approve their own request, here or in the database', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        expect(fn () => app(ApproveAdjustment::class)->handle($this->admin, $adjustment->id))
            ->toThrow(ValidationException::class);

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and(LedgerEntry::query()->count())->toBe(1);

        // Even a hand-written UPDATE cannot make the requester the approver.
        DB::statement('SAVEPOINT self_approve');
        expect(fn () => DB::table('adjustments')->where('id', $adjustment->id)->update([
            'status' => 'APPROVED', 'approved_by' => $this->admin->id, 'decided_at' => now(),
        ]))->toThrow(QueryException::class);
        DB::statement('ROLLBACK TO SAVEPOINT self_approve');
    });

    it('is refused for a user without the permission', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        expect(fn () => app(ApproveAdjustment::class)->handle($this->cashier, $adjustment->id))
            ->toThrow(AuthorizationException::class)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('cannot decide the same request twice', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');
        app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id);

        expect(fn () => app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id))->toThrow(ValidationException::class)
            ->and(fn () => app(RejectAdjustment::class)->handle($this->owner, $adjustment->id, 'Too late'))->toThrow(ValidationException::class)
            ->and(LedgerEntry::query()->where('entry_type', 'ADJUSTMENT')->count())->toBe(1);
    });

    it('refuses a stale approval and posts nothing (INV-06)', function (): void {
        // Two requests of ₱600 each against a ₱999 balance: each fits alone, both do not.
        $first = askFor($this, AdjustmentKind::Credit, '600.00');
        $second = askFor($this, AdjustmentKind::Credit, '600.00');

        app(ApproveAdjustment::class)->handle($this->owner, $first->id);

        expect(fn () => app(ApproveAdjustment::class)->handle($this->owner, $second->id))->toThrow(ValidationException::class);

        expect($second->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and(LedgerEntry::query()->where('entry_type', 'ADJUSTMENT')->count())->toBe(1)
            ->and(adjustedBalanceOf($this->invoice))->toBe(39900);
    });

    it('leaves nothing behind when the ledger post fails (INV-11)', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');
        // A ledger row for this adjustment already exists, so the unique index rejects the second.
        BillingData::ledger($this->account, $this->owner->id, [
            'entry_type' => 'ADJUSTMENT', 'debit_centavos' => 0, 'credit_centavos' => 1,
            'reference_type' => 'adjustment', 'reference_id' => $adjustment->id,
        ]);

        DB::statement('SAVEPOINT approve_fails');
        expect(fn () => app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id))->toThrow(QueryException::class);
        DB::statement('ROLLBACK TO SAVEPOINT approve_fails');

        expect($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending)
            ->and(AuditLog::query()->where('action', AuditAction::AdjustmentApproved->value)->count())->toBe(0);
    });
});

describe('rejecting', function (): void {
    it('records the decision and reason, and writes no ledger entry', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        $done = app(RejectAdjustment::class)->handle($this->owner, $adjustment->id, 'No evidence of the promo');

        expect($done->status)->toBe(AdjustmentStatus::Rejected)
            ->and($done->approved_by)->toBe($this->owner->id)
            ->and($done->decision_note)->toBe('No evidence of the promo')
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and(adjustedBalanceOf($this->invoice))->toBe(99900)
            ->and(AuditLog::query()->where('action', AuditAction::AdjustmentRejected->value)->count())->toBe(1);
    });

    it('needs a reason and a different user', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');

        expect(fn () => app(RejectAdjustment::class)->handle($this->owner, $adjustment->id, ' '))->toThrow(ValidationException::class)
            ->and(fn () => app(RejectAdjustment::class)->handle($this->admin, $adjustment->id, 'Changed my mind'))->toThrow(ValidationException::class)
            ->and(fn () => app(RejectAdjustment::class)->handle($this->cashier, $adjustment->id, 'No'))->toThrow(AuthorizationException::class)
            ->and($adjustment->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });
});

describe('decided requests are permanent (INV-05)', function (): void {
    it('cannot be edited or deleted, by the model or the database', function (): void {
        $adjustment = askFor($this, AdjustmentKind::Credit, '250.00');
        app(ApproveAdjustment::class)->handle($this->owner, $adjustment->id);
        $adjustment = $adjustment->fresh();

        expect(fn () => $adjustment->forceFill(['reason' => 'Edited'])->save())->toThrow(LogicException::class)
            ->and(fn () => $adjustment->delete())->toThrow(LogicException::class);

        foreach ([
            fn () => DB::table('adjustments')->where('id', $adjustment->id)->update(['amount_centavos' => 1]),
            fn () => DB::table('adjustments')->where('id', $adjustment->id)->delete(),
        ] as $i => $attempt) {
            DB::statement("SAVEPOINT guard_$i");
            expect($attempt)->toThrow(QueryException::class, 'append-only');
            DB::statement("ROLLBACK TO SAVEPOINT guard_$i");
        }
    });
});

describe('voiding (FR-BIL-013)', function (): void {
    it('keeps the number, cancels the balance with one ledger credit and marks the invoice VOID', function (): void {
        $number = $this->invoice->invoice_no;
        $void = askFor($this, AdjustmentKind::Void, null, 'Billed to the wrong account');

        app(ApproveAdjustment::class)->handle($this->owner, $void->id);

        $invoice = $this->invoice->fresh();
        expect($invoice->status)->toBe(InvoiceStatus::Void)
            ->and($invoice->invoice_no)->toBe($number)
            ->and($invoice->voided_at)->not->toBeNull()
            ->and($invoice->void_reason)->toBe('Billed to the wrong account')
            ->and($invoice->total_centavos->centavos)->toBe(99900)
            ->and(adjustedBalanceOf($invoice))->toBe(0)
            ->and(ledgerBalanceOf($this->account))->toBe(0);

        $entry = LedgerEntry::query()->where('entry_type', 'ADJUSTMENT')->sole();
        expect($entry->credit_centavos->centavos)->toBe(99900)
            ->and($entry->reference_no)->toBe($void->adjustment_no);

        $log = AuditLog::query()->where('action', AuditAction::InvoiceVoided->value)->sole();
        expect($log->old_values['status'])->toBe('UNPAID')
            ->and($log->new_values['invoice_no'])->toBe($number)
            ->and($log->reason)->toBe('Billed to the wrong account');
    });

    it('voids only what is still owed after an earlier credit', function (): void {
        $credit = askFor($this, AdjustmentKind::Credit, '400.00');
        app(ApproveAdjustment::class)->handle($this->owner, $credit->id);

        $void = askFor($this, AdjustmentKind::Void, null, 'Cancelled service');
        app(ApproveAdjustment::class)->handle($this->owner, $void->id);

        expect($void->fresh()->amount_centavos->centavos)->toBe(59900)
            ->and(ledgerBalanceOf($this->account))->toBe(0)
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Void);
    });

    it('refuses a void approval when the balance has changed since the request', function (): void {
        $void = askFor($this, AdjustmentKind::Void, null, 'Cancelled service');
        $credit = askFor($this, AdjustmentKind::Credit, '100.00');
        app(ApproveAdjustment::class)->handle($this->owner, $credit->id);

        expect(fn () => app(ApproveAdjustment::class)->handle($this->owner, $void->id))->toThrow(ValidationException::class)
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
            ->and($void->fresh()->status)->toBe(AdjustmentStatus::Pending);
    });

    it('refuses a void while payments are applied to the invoice', function (): void {
        $this->app->bind(AllocationTotals::class, fn () => new class implements AllocationTotals
        {
            public function forInvoices(array $invoiceIds): array
            {
                return array_fill_keys($invoiceIds, Money::fromCentavos(30000));
            }
        });

        expect(fn () => askFor($this, AdjustmentKind::Void, null, 'Cancelled service'))->toThrow(ValidationException::class)
            ->and(Adjustment::query()->count())->toBe(0);
    });

    it('needs the void permission, and a voided invoice accepts no further adjustment', function (): void {
        $void = askFor($this, AdjustmentKind::Void, null, 'Cancelled service');
        expect(fn () => app(ApproveAdjustment::class)->handle($this->cashier, $void->id))->toThrow(AuthorizationException::class);

        app(ApproveAdjustment::class)->handle($this->owner, $void->id);

        expect(fn () => askFor($this, AdjustmentKind::Debit, '10.00'))->toThrow(ValidationException::class);
    });

    it('never reuses the number of a voided invoice (FR-BIL-013)', function (): void {
        $void = askFor($this, AdjustmentKind::Void, null, 'Cancelled service');
        app(ApproveAdjustment::class)->handle($this->owner, $void->id);

        $next = BillingData::postedInvoice($this->account, $this->owner->id);

        expect($next->invoice_no)->not->toBe($this->invoice->invoice_no)
            ->and((int) substr($next->invoice_no, 4))->toBeGreaterThan((int) substr($this->invoice->invoice_no, 4));
    });
});
