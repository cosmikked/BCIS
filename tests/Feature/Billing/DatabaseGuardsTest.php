<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingData;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-BIL-003, FR-BIL-005, FR-BIL-006, FR-BIL-009, FR-BIL-013, INV-02, INV-05, INV-09, INV-10
//
// These tests go straight to the database on purpose. The rules must hold even
// if application code is buggy or someone opens psql as the table owner.

uses(RefreshDatabase::class);

/** Runs the attempt in a savepoint so PostgreSQL's aborted-transaction state stays contained. */
function guardsRejects(Closure $attempt, string $message = ''): void
{
    expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class, $message);
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->account = SubscriberData::account($this->owner);
});

describe('duplicate billing (FR-BIL-003, INV-09)', function (): void {
    it('allows one non-void MONTHLY invoice per account and period', function (): void {
        BillingData::invoice($this->account);

        guardsRejects(fn () => BillingData::invoice($this->account), 'invoices_monthly_period_unique');
    });

    it('frees the slot once the invoice is void, and keeps the voided row (FR-BIL-013)', function (): void {
        $first = BillingData::invoice($this->account, ['status' => 'UNPAID']);

        DB::table('invoices')->where('id', $first)->update(['status' => 'VOID', 'voided_at' => now(), 'void_reason' => 'Billed in error']);
        BillingData::invoice($this->account);

        expect(DB::table('invoices')->where('service_account_id', $this->account->id)->count())->toBe(2);
    });

    it('does not apply to ONE_OFF invoices (FR-BIL-010)', function (): void {
        BillingData::invoice($this->account);
        BillingData::invoice($this->account, ['invoice_type' => 'ONE_OFF', 'status' => 'UNPAID']);
        BillingData::invoice($this->account, ['invoice_type' => 'ONE_OFF', 'status' => 'UNPAID']);

        expect(DB::table('invoices')->count())->toBe(3);
    });

    it('allows the same period for a different service account', function (): void {
        $other = SubscriberData::account($this->owner);
        BillingData::invoice($this->account);
        BillingData::invoice($other);

        expect(DB::table('invoices')->count())->toBe(2);
    });

    it('allows one billing cycle per month', function (): void {
        BillingData::cycle('2026-10-01');

        guardsRejects(fn () => DB::table('billing_cycles')->insert([
            'period_month' => '2026-10-01',
            'generated_by' => $this->owner->id,
        ]), 'billing_cycles_period_month_unique');
    });

    it('requires the cycle period to be the first of a month', function (): void {
        guardsRejects(fn () => DB::table('billing_cycles')->insert([
            'period_month' => '2026-10-15',
            'generated_by' => $this->owner->id,
        ]), 'billing_cycles_first_of_month_check');
    });
});

describe('invoice numbers (INV-10)', function (): void {
    it('draws numbers from the sequence, which only ever moves forward', function (): void {
        $a = (int) DB::selectOne("SELECT nextval('invoice_no_seq') AS n")->n;
        $b = (int) DB::selectOne("SELECT nextval('invoice_no_seq') AS n")->n;

        expect($b)->toBeGreaterThan($a);
    });

    it('rejects a duplicate invoice number', function (): void {
        $other = SubscriberData::account($this->owner);
        DB::table('invoices')->insert([
            'invoice_no' => 'INV-000001', 'billing_cycle_id' => BillingData::cycle('2026-10-01'),
            'service_account_id' => $this->account->id, 'subscriber_id' => $this->account->subscriber_id,
            'invoice_type' => 'MONTHLY', 'billing_period' => '2026-10-01', 'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-05', 'total_centavos' => 100, 'status' => 'UNPAID', 'finalized_at' => now(),
        ]);

        guardsRejects(fn () => DB::table('invoices')->insert([
            'invoice_no' => 'INV-000001', 'billing_cycle_id' => BillingData::cycle('2026-10-01'),
            'service_account_id' => $other->id, 'subscriber_id' => $other->subscriber_id,
            'invoice_type' => 'MONTHLY', 'billing_period' => '2026-10-01', 'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-05', 'total_centavos' => 100, 'status' => 'UNPAID', 'finalized_at' => now(),
        ]), 'invoices_invoice_no_unique');
    });

    it('gives a number to a finalized invoice and none to a draft', function (): void {
        guardsRejects(fn () => BillingData::invoice($this->account, ['status' => 'UNPAID', 'invoice_no' => null]), 'invoices_number_check');
        guardsRejects(fn () => BillingData::invoice($this->account, ['status' => 'DRAFT', 'invoice_no' => 'INV-000777']), 'invoices_number_check');
    });
});

describe('finalized invoices are immutable (FR-BIL-009, INV-05)', function (): void {
    it('rejects changing the money, dates, owner or number of a finalized invoice', function (string $column, mixed $value): void {
        $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);

        guardsRejects(fn () => DB::table('invoices')->where('id', $id)->update([$column => $value]), 'append-only');
    })->with([
        'total' => ['total_centavos', 1],
        'due date' => ['due_date', '2026-12-31'],
        'invoice date' => ['invoice_date', '2026-09-01'],
        'invoice number' => ['invoice_no', 'INV-999999'],
        'period' => ['billing_period', '2026-09-01'],
        'type' => ['invoice_type', 'ONE_OFF'],
        'back to draft' => ['status', 'DRAFT'],
    ]);

    it('rejects deleting a finalized invoice', function (): void {
        $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);

        guardsRejects(fn () => DB::table('invoices')->where('id', $id)->delete(), 'append-only');
        expect(DB::table('invoices')->where('id', $id)->exists())->toBeTrue();
    });

    it('rejects truncating invoices', function (): void {
        BillingData::invoice($this->account, ['status' => 'UNPAID']);

        // A plain TRUNCATE is already refused because invoice_items references the
        // table; CASCADE is the form that would get past that, so the trigger
        // must stop it.
        guardsRejects(fn () => DB::statement('TRUNCATE invoices CASCADE'), 'append-only');
        guardsRejects(fn () => DB::statement('TRUNCATE invoice_items'), 'append-only');
    });

    it('still lets the status follow the balance', function (): void {
        $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);

        DB::table('invoices')->where('id', $id)->update(['status' => 'PARTIALLY_PAID']);
        DB::table('invoices')->where('id', $id)->update(['status' => 'PAID']);

        expect(DB::table('invoices')->where('id', $id)->value('status'))->toBe('PAID');
    });

    it('keeps a voided invoice visible, numbered and void', function (): void {
        $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);
        $number = DB::table('invoices')->where('id', $id)->value('invoice_no');
        DB::table('invoices')->where('id', $id)->update(['status' => 'VOID', 'voided_at' => now(), 'void_reason' => 'Billed in error']);

        guardsRejects(fn () => DB::table('invoices')->where('id', $id)->update(['status' => 'UNPAID']), 'append-only');
        guardsRejects(fn () => DB::table('invoices')->where('id', $id)->update(['void_reason' => 'edited']), 'append-only');
        expect(DB::table('invoices')->where('id', $id)->value('invoice_no'))->toBe($number);
    });

    it('requires a void to carry its time and reason', function (): void {
        $id = BillingData::invoice($this->account, ['status' => 'UNPAID']);

        guardsRejects(fn () => DB::table('invoices')->where('id', $id)->update(['status' => 'VOID']), 'invoices_void_check');
    });

    it('lets a DRAFT be edited and discarded (FR-BIL-004)', function (): void {
        $id = BillingData::invoice($this->account);

        DB::table('invoices')->where('id', $id)->update(['total_centavos' => 123456]);
        DB::table('invoices')->where('id', $id)->delete();

        expect(DB::table('invoices')->where('id', $id)->exists())->toBeFalse();
    });
});

describe('invoice items (FR-BIL-006, INV-05)', function (): void {
    it('lets items of a DRAFT invoice be added, changed and removed', function (): void {
        $invoice = BillingData::invoice($this->account);
        $item = BillingData::item($invoice);

        DB::table('invoice_items')->where('id', $item)->update(['amount_centavos' => 50000]);
        DB::table('invoice_items')->where('id', $item)->delete();

        expect(DB::table('invoice_items')->count())->toBe(0);
    });

    it('rejects inserting, updating and deleting items of a finalized invoice', function (): void {
        $invoice = BillingData::invoice($this->account);
        $item = BillingData::item($invoice);
        DB::table('invoices')->where('id', $invoice)->update(['status' => 'UNPAID', 'invoice_no' => 'INV-000042', 'finalized_at' => now()]);

        guardsRejects(fn () => BillingData::item($invoice), 'append-only');
        guardsRejects(fn () => DB::table('invoice_items')->where('id', $item)->update(['amount_centavos' => 1]), 'append-only');
        guardsRejects(fn () => DB::table('invoice_items')->where('id', $item)->delete(), 'append-only');
        expect((int) DB::table('invoice_items')->where('invoice_id', $invoice)->sum('amount_centavos'))->toBe(99900);
    });

    it('rejects moving an item onto a finalized invoice', function (): void {
        $draft = BillingData::invoice($this->account);
        $item = BillingData::item($draft);
        $other = SubscriberData::account($this->owner);
        $finalized = BillingData::invoice($other, ['status' => 'UNPAID']);

        guardsRejects(fn () => DB::table('invoice_items')->where('id', $item)->update(['invoice_id' => $finalized]), 'append-only');
    });

    it('keeps discounts negative and charges positive', function (): void {
        $invoice = BillingData::invoice($this->account);

        guardsRejects(fn () => BillingData::item($invoice, ['item_type' => 'DISCOUNT', 'amount_centavos' => 5000]), 'invoice_items_sign_check');
        guardsRejects(fn () => BillingData::item($invoice, ['item_type' => 'INSTALLATION', 'amount_centavos' => -5000]), 'invoice_items_sign_check');
        BillingData::item($invoice, ['item_type' => 'DISCOUNT', 'amount_centavos' => -5000]);

        expect(DB::table('invoice_items')->count())->toBe(1);
    });
});

describe('billing cycles (FR-BIL-004)', function (): void {
    it('never deletes a cycle and never edits a finalized one', function (): void {
        $id = BillingData::cycle('2026-10-01');

        guardsRejects(fn () => DB::table('billing_cycles')->where('id', $id)->delete(), 'append-only');

        DB::table('billing_cycles')->where('id', $id)->update([
            'status' => 'FINALIZED', 'finalized_by' => $this->owner->id, 'finalized_at' => now(),
        ]);

        guardsRejects(fn () => DB::table('billing_cycles')->where('id', $id)->update(['invoice_count' => 5]), 'append-only');
    });

    it('requires a finalized cycle to say who finalized it and when', function (): void {
        $id = BillingData::cycle('2026-10-01');

        guardsRejects(fn () => DB::table('billing_cycles')->where('id', $id)->update(['status' => 'FINALIZED']), 'billing_cycles_finalized_check');
    });
});

describe('ledger entries are append-only (FR-BIL-014, INV-02)', function (): void {
    it('rejects UPDATE, DELETE and TRUNCATE, even for the table owner', function (): void {
        BillingData::ledger($this->account, $this->owner->id, ['reference_type' => 'invoice', 'reference_id' => 1]);

        guardsRejects(fn () => DB::statement('UPDATE ledger_entries SET debit_centavos = 1'), 'append-only');
        guardsRejects(fn () => DB::statement('DELETE FROM ledger_entries'), 'append-only');
        guardsRejects(fn () => DB::statement('TRUNCATE ledger_entries'), 'append-only');
        expect((int) DB::table('ledger_entries')->value('debit_centavos'))->toBe(99900);
    });

    it('requires exactly one side with a positive amount', function (int $debit, int $credit): void {
        guardsRejects(fn () => BillingData::ledger($this->account, $this->owner->id, [
            'debit_centavos' => $debit, 'credit_centavos' => $credit,
        ]), 'ledger_entries_one_side_check');
    })->with([
        'both sides' => [100, 100],
        'neither side' => [0, 0],
        'negative debit' => [-100, 0],
        'negative credit' => [0, -100],
    ]);

    it('accepts a debit or a credit', function (): void {
        BillingData::ledger($this->account, $this->owner->id);
        BillingData::ledger($this->account, $this->owner->id, ['entry_type' => 'ADJUSTMENT', 'debit_centavos' => 0, 'credit_centavos' => 5000]);

        expect(DB::table('ledger_entries')->count())->toBe(2);
    });

    it('posts an invoice or an adjustment to the ledger only once', function (): void {
        BillingData::ledger($this->account, $this->owner->id, ['reference_type' => 'invoice', 'reference_id' => 7]);

        guardsRejects(fn () => BillingData::ledger($this->account, $this->owner->id, ['reference_type' => 'invoice', 'reference_id' => 7]), 'ledger_entries_invoice_once');

        BillingData::ledger($this->account, $this->owner->id, ['entry_type' => 'ADJUSTMENT', 'debit_centavos' => 0, 'credit_centavos' => 100, 'reference_type' => 'adjustment', 'reference_id' => 7]);
        guardsRejects(fn () => BillingData::ledger($this->account, $this->owner->id, ['entry_type' => 'ADJUSTMENT', 'debit_centavos' => 0, 'credit_centavos' => 100, 'reference_type' => 'adjustment', 'reference_id' => 7]), 'ledger_entries_adjustment_once');
    });

    it('keeps the foreign keys restrictive', function (): void {
        BillingData::ledger($this->account, $this->owner->id);

        guardsRejects(fn () => DB::table('subscribers')->where('id', $this->account->subscriber_id)->delete());
    });
});

describe('adjustments and approval (FR-BIL-009, PRD 19 #8)', function (): void {
    beforeEach(function (): void {
        $this->invoice = BillingData::invoice($this->account, ['status' => 'UNPAID']);
    });

    it('starts as a PENDING request with nobody approving', function (): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);

        expect(DB::table('adjustments')->where('id', $id)->value('status'))->toBe('PENDING');
    });

    it('lets a different user approve', function (): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);

        DB::table('adjustments')->where('id', $id)->update([
            'status' => 'APPROVED', 'approved_by' => $this->owner->id, 'decided_at' => now(),
        ]);

        expect(DB::table('adjustments')->where('id', $id)->value('status'))->toBe('APPROVED');
    });

    it('refuses approval by the person who requested it', function (): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);

        guardsRejects(fn () => DB::table('adjustments')->where('id', $id)->update([
            'status' => 'APPROVED', 'approved_by' => $this->admin->id, 'decided_at' => now(),
        ]), 'adjustments_decision_check');
    });

    it('requires a reason for a rejection', function (): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);

        guardsRejects(fn () => DB::table('adjustments')->where('id', $id)->update([
            'status' => 'REJECTED', 'approved_by' => $this->owner->id, 'decided_at' => now(),
        ]), 'adjustments_decision_check');
    });

    it('does not let a pending request be rewritten', function (string $column, mixed $value): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);

        guardsRejects(fn () => DB::table('adjustments')->where('id', $id)->update([$column => $value]), 'append-only');
    })->with([
        'amount' => ['amount_centavos', 1],
        'reason' => ['reason', 'changed my mind'],
        'kind' => ['kind', 'DEBIT'],
        'requester' => ['requested_by', 0],
    ]);

    it('freezes a decided adjustment, and never deletes one', function (): void {
        $id = BillingData::adjustment($this->account, $this->invoice, $this->admin->id);
        DB::table('adjustments')->where('id', $id)->update([
            'status' => 'APPROVED', 'approved_by' => $this->owner->id, 'decided_at' => now(),
        ]);

        guardsRejects(fn () => DB::table('adjustments')->where('id', $id)->update(['decision_note' => 'edited']), 'append-only');
        guardsRejects(fn () => DB::table('adjustments')->where('id', $id)->delete(), 'append-only');
        guardsRejects(fn () => DB::statement('TRUNCATE adjustments'), 'append-only');
    });

    it('needs a positive amount and a real reason', function (): void {
        guardsRejects(fn () => BillingData::adjustment($this->account, $this->invoice, $this->admin->id, ['amount_centavos' => 0]), 'adjustments_amount_check');
        guardsRejects(fn () => BillingData::adjustment($this->account, $this->invoice, $this->admin->id, ['reason' => '  ']), 'adjustments_reason_check');
    });

    it('rejects a duplicate adjustment number', function (): void {
        BillingData::adjustment($this->account, $this->invoice, $this->admin->id, ['adjustment_no' => 'ADJ-000001']);

        guardsRejects(fn () => BillingData::adjustment($this->account, $this->invoice, $this->admin->id, ['adjustment_no' => 'ADJ-000001']), 'adjustments_adjustment_no_unique');
    });

    it('draws adjustment numbers from a sequence', function (): void {
        $a = (int) DB::selectOne("SELECT nextval('adjustment_no_seq') AS n")->n;
        $b = (int) DB::selectOne("SELECT nextval('adjustment_no_seq') AS n")->n;

        expect($b)->toBeGreaterThan($a);
    });
});

describe('service account installation fee (FR-BIL-006)', function (): void {
    it('defaults to no fee and rejects a negative one', function (): void {
        expect((int) DB::table('service_accounts')->where('id', $this->account->id)->value('installation_fee_centavos'))->toBe(0);

        guardsRejects(fn () => DB::table('service_accounts')->where('id', $this->account->id)->update(['installation_fee_centavos' => -1]), 'service_accounts_installation_fee_check');
    });
});
