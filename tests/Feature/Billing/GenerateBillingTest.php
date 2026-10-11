<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\DiscardDraftInvoices;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Actions\PreviewBilling;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\SkipReason;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\AuditLog;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BillingData;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-BIL-001, FR-BIL-002, FR-BIL-003, FR-BIL-004, FR-BIL-006, FR-BIL-007, INV-09, INV-20, AT-11

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Business dates matter here, so the clock is fixed (Asia/Manila).
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);
    $this->plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
});

/** Invoices of the October cycle, by service account id. */
function octoberInvoices(): array
{
    return Invoice::query()->where('billing_period', '2026-10-01')->get()->keyBy('service_account_id')->all();
}

describe('preview (FR-BIL-001, FR-BIL-002)', function (): void {
    it('lists who is billed and the total, and saves nothing', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);

        $preview = app(PreviewBilling::class)->handle($this->owner, '2026-10');

        expect($preview->plan->lines)->toHaveCount(2)
            ->and($preview->plan->total()->centavos)->toBe(199800)
            ->and($preview->cycle)->toBeNull()
            ->and(Invoice::query()->count())->toBe(0)
            ->and(BillingCycle::query()->count())->toBe(0);
    });

    it('skips an account for every reason, and says why', function (): void {
        $billable = BillingData::activeAccount($this->owner, plan: $this->plan);
        $pending = SubscriberData::account($this->owner, plan: $this->plan);
        $suspended = BillingData::activeAccount($this->owner, plan: $this->plan);
        app(ChangeServiceStatus::class)->handle($this->owner, $suspended->id, ServiceStatus::Suspended, 'Unpaid');
        $terminated = BillingData::activeAccount($this->owner, plan: $this->plan);
        app(ChangeServiceStatus::class)->handle($this->owner, $terminated->id, ServiceStatus::Terminated, 'Left');
        $notStarted = BillingData::activeAccount($this->owner, ['billing_start_date' => '2026-11-01'], $this->plan);
        $inactive = BillingData::activeAccount($this->owner, plan: $this->plan);
        app(ChangeSubscriberStatus::class)->handle($this->owner, $inactive->subscriber_id, SubscriberStatus::Inactive, 'Paused');

        $preview = app(PreviewBilling::class)->handle($this->owner, '2026-10-01');
        $reasons = collect($preview->plan->skipped)->mapWithKeys(fn ($s) => [$s->account->id => $s->reason]);

        expect(collect($preview->plan->lines)->pluck('account.id')->all())->toBe([$billable->id])
            ->and($reasons[$pending->id])->toBe(SkipReason::Pending)
            ->and($reasons[$suspended->id])->toBe(SkipReason::Suspended)
            ->and($reasons[$terminated->id])->toBe(SkipReason::Terminated)
            ->and($reasons[$notStarted->id])->toBe(SkipReason::NotStarted)
            ->and($reasons[$inactive->id])->toBe(SkipReason::SubscriberInactive)
            ->and($reasons[$suspended->id]->value)->toBe('suspended');
    });

    it('bills the month that contains the billing start date in full (PRD 19 #5)', function (): void {
        $account = BillingData::activeAccount($this->owner, ['billing_start_date' => '2026-10-20'], $this->plan);

        $preview = app(PreviewBilling::class)->handle($this->owner, '2026-10');

        expect($preview->plan->lines[0]->account->id)->toBe($account->id)
            ->and($preview->plan->lines[0]->total()->centavos)->toBe(99900);
    });

    it('shows the installation fee on the first invoice only (PRD 19 #6)', function (): void {
        BillingData::activeAccount($this->owner, ['installation_fee' => '500.00'], $this->plan);

        $line = app(PreviewBilling::class)->handle($this->owner, '2026-10')->plan->lines[0];

        expect($line->hasInstallationFee())->toBeTrue()
            ->and($line->installationFee->centavos)->toBe(50000)
            ->and($line->total()->centavos)->toBe(149900);
    });

    it('applies the short-month due date fallback (FR-BIL-007)', function (): void {
        $this->travelTo('2026-11-09 09:00:00');
        BillingData::activeAccount($this->owner, ['billing_due_day' => 31], $this->plan);

        $line = app(PreviewBilling::class)->handle($this->owner, '2026-11')->plan->lines[0];

        expect($line->dueDate->toDateString())->toBe('2026-11-30')
            ->and($line->invoiceDate->toDateString())->toBe('2026-11-01');
    });

    it('reports an account already billed as skipped, not billed twice', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        $preview = app(PreviewBilling::class)->handle($this->owner, '2026-10');

        expect($preview->plan->lines)->toBe([])
            ->and($preview->plan->skippedCount(SkipReason::AlreadyBilled))->toBe(1)
            ->and($preview->cycle?->status)->toBe(BillingCycleStatus::Draft);
    });

    it('refuses a month that has not started, and a nonsense month', function (): void {
        expect(fn () => app(PreviewBilling::class)->handle($this->owner, '2026-11'))->toThrow(ValidationException::class)
            ->and(fn () => app(PreviewBilling::class)->handle($this->owner, 'next month'))->toThrow(ValidationException::class)
            ->and(fn () => app(PreviewBilling::class)->handle($this->owner, '2026-13'))->toThrow(ValidationException::class);
    });

    it('needs the billing.generate permission', function (): void {
        expect(fn () => app(PreviewBilling::class)->handle($this->cashier, '2026-10'))->toThrow(AuthorizationException::class);
    });
});

describe('generate (FR-BIL-004, FR-BIL-006)', function (): void {
    it('creates DRAFT invoices with items, no number and no ledger entry', function (): void {
        $account = BillingData::activeAccount($this->owner, plan: $this->plan);

        $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        $invoice = octoberInvoices()[$account->id];

        expect($result->created)->toBe(1)
            ->and($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and($invoice->invoice_no)->toBeNull()
            ->and($invoice->finalized_at)->toBeNull()
            ->and($invoice->total_centavos->centavos)->toBe(99900)
            ->and($invoice->invoice_date->toDateString())->toBe('2026-10-01')
            ->and($invoice->due_date->toDateString())->toBe('2026-10-05')
            ->and($invoice->subscriber_id)->toBe($account->subscriber_id)
            ->and($invoice->items)->toHaveCount(1)
            ->and($invoice->items[0]->item_type)->toBe(InvoiceItemType::Subscription)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('keeps the cycle totals equal to its invoices', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);

        $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect($result->cycle->status)->toBe(BillingCycleStatus::Draft)
            ->and($result->cycle->invoice_count)->toBe(2)
            ->and($result->cycle->total_centavos->centavos)->toBe(99900 + 99900 + 50000)
            ->and($result->cycle->period_month->toDateString())->toBe('2026-10-01');
    });

    it('makes the invoice total equal the sum of its items for every invoice (FR-BIL-006)', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);

        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        $mismatches = DB::select('SELECT i.id FROM invoices i JOIN invoice_items it ON it.invoice_id = i.id GROUP BY i.id, i.total_centavos HAVING SUM(it.amount_centavos) <> i.total_centavos');

        expect($mismatches)->toBe([])
            ->and(Invoice::query()->count())->toBe(2);
    });

    it('adds the installation fee as its own item on the first invoice', function (): void {
        $account = BillingData::activeAccount($this->owner, ['installation_fee' => '500.00'], $this->plan);

        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        $items = octoberInvoices()[$account->id]->items->keyBy(fn ($i) => $i->item_type->value);

        expect($items)->toHaveCount(2)
            ->and($items['SUBSCRIPTION']->amount_centavos->centavos)->toBe(99900)
            ->and($items['INSTALLATION']->amount_centavos->centavos)->toBe(50000);
    });

    it('bills no fee when the account has none, whatever the plan carries', function (): void {
        // The factory plan has a 500.00 installation fee; the account did not take it.
        $account = BillingData::activeAccount($this->owner, plan: $this->plan);

        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect(octoberInvoices()[$account->id]->items)->toHaveCount(1);
    });

    it('bills the rate the account was created with, not the current plan price (INV-20)', function (): void {
        $account = BillingData::activeAccount($this->owner, plan: $this->plan);
        $this->plan->forceFill(['price_centavos' => Money::fromCentavos(150000)])->save();

        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect(octoberInvoices()[$account->id]->total_centavos->centavos)->toBe(99900);
    });

    it('bills nobody for a month with no billable account, and still records the run', function (): void {
        SubscriberData::account($this->owner, plan: $this->plan);

        $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect($result->created)->toBe(0)
            ->and($result->ineligible)->toBe(1)
            ->and($result->cycle->invoice_count)->toBe(0);
    });

    it('writes an audit record with the counts', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);

        $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        $log = AuditLog::query()->where('action', AuditAction::BillingCycleGenerated->value)->firstOrFail();

        expect($log->actor_user_id)->toBe($this->owner->id)
            ->and($log->entity_id)->toBe($result->cycle->id)
            ->and($log->new_values['created'])->toBe(1)
            ->and($log->new_values['cycle_total_centavos'])->toBe(99900);
    });

    it('needs the billing.generate permission', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);

        expect(fn () => app(GenerateBillingCycle::class)->handle($this->cashier, '2026-10'))->toThrow(AuthorizationException::class)
            ->and(Invoice::query()->count())->toBe(0);
    });
});

describe('running it twice (FR-BIL-003, INV-09, AT-11)', function (): void {
    it('leaves one invoice per account and reports the second run as already billed', function (): void {
        $a = BillingData::activeAccount($this->owner, plan: $this->plan);
        $b = BillingData::activeAccount($this->owner, plan: $this->plan);

        $first = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        $second = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect($first->created)->toBe(2)
            ->and($second->created)->toBe(0)
            ->and($second->alreadyBilled)->toBe(2)
            ->and($second->cycle->id)->toBe($first->cycle->id)
            ->and(Invoice::query()->where('service_account_id', $a->id)->count())->toBe(1)
            ->and(Invoice::query()->where('service_account_id', $b->id)->count())->toBe(1)
            ->and(BillingCycle::query()->count())->toBe(1)
            ->and($second->cycle->invoice_count)->toBe(2);
    });

    it('bills only the newly eligible account on a re-run of a draft month', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');
        $late = BillingData::activeAccount($this->owner, plan: $this->plan);

        $again = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect($again->created)->toBe(1)
            ->and(octoberInvoices())->toHaveKey($late->id)
            ->and(Invoice::query()->count())->toBe(2)
            ->and($again->cycle->invoice_count)->toBe(2);
    });

    it('refuses to generate a finalized month again (FR-BIL-004)', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
        DB::table('billing_cycles')->where('id', $cycle->id)->update([
            'status' => 'FINALIZED', 'finalized_by' => $this->owner->id, 'finalized_at' => now(),
        ]);

        expect(fn () => app(GenerateBillingCycle::class)->handle($this->owner, '2026-10'))->toThrow(ValidationException::class);
    });

    it('does not bill an account again while the database holds its invoice, whatever the code thinks (INV-09)', function (): void {
        $account = BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        // A second MONTHLY invoice for the same month is impossible even by hand.
        expect(fn () => DB::transaction(fn () => BillingData::invoice($account, ['billing_cycle_id' => $cycle->id])))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('discard drafts (FR-BIL-004)', function (): void {
    it('removes the drafts and their items, empties the cycle and lets the month be regenerated', function (): void {
        BillingData::activeAccount($this->owner, ['installation_fee' => '500'], $this->plan);
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        $discarded = app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Wrong rate on one plan');

        expect($discarded->status)->toBe(BillingCycleStatus::Draft)
            ->and($discarded->invoice_count)->toBe(0)
            ->and($discarded->total_centavos->centavos)->toBe(0)
            ->and(Invoice::query()->count())->toBe(0)
            ->and(DB::table('invoice_items')->count())->toBe(0);

        $again = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

        expect($again->created)->toBe(2)
            ->and($again->cycle->id)->toBe($cycle->id)
            ->and($again->cycle->total_centavos->centavos)->toBe(99900 + 50000 + 99900);
    });

    it('records who discarded, why, and how many', function (): void {
        BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Wrong rate on one plan');
        $log = AuditLog::query()->where('action', AuditAction::BillingDraftsDiscarded->value)->firstOrFail();

        expect($log->reason)->toBe('Wrong rate on one plan')
            ->and($log->old_values['invoice_count'])->toBe(1)
            ->and($log->old_values['total_centavos'])->toBe(99900)
            ->and($log->actor_user_id)->toBe($this->owner->id);
    });

    it('needs a reason', function (): void {
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        expect(fn () => app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, '  '))->toThrow(ValidationException::class);
    });

    it('needs the billing.generate permission', function (): void {
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        expect(fn () => app(DiscardDraftInvoices::class)->handle($this->cashier, $cycle->id, 'Wrong rate'))->toThrow(AuthorizationException::class);
    });

    it('never touches a finalized cycle or its numbered invoices', function (): void {
        $account = BillingData::activeAccount($this->owner, plan: $this->plan);
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;

        // Finalize by hand (FinalizeBillingCycle is the next step): number the invoice, then the cycle.
        $invoice = octoberInvoices()[$account->id];
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'UNPAID', 'invoice_no' => 'INV-000001', 'finalized_at' => now()]);
        DB::table('billing_cycles')->where('id', $cycle->id)->update([
            'status' => 'FINALIZED', 'finalized_by' => $this->owner->id, 'finalized_at' => now(),
        ]);

        expect(fn () => app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Try to undo'))->toThrow(ValidationException::class)
            ->and(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue()
            ->and(DB::table('invoice_items')->where('invoice_id', $invoice->id)->count())->toBe(1);
    });
});

describe('optional installation fee on a service account (FR-BIL-006)', function (): void {
    it('defaults to no fee and is a snapshot in centavos', function (): void {
        $none = SubscriberData::account($this->owner, plan: $this->plan);
        $fee = SubscriberData::account($this->owner, plan: $this->plan, overrides: ['installation_fee' => '500.00']);

        expect($none->installation_fee_centavos->centavos)->toBe(0)
            ->and($none->installation_fee_billed_at)->toBeNull()
            ->and($fee->installation_fee_centavos->centavos)->toBe(50000);
    });

    it('rejects a fee that is not an amount of pesos', function (string $value): void {
        expect(fn () => SubscriberData::account($this->owner, plan: $this->plan, overrides: ['installation_fee' => $value]))
            ->toThrow(ValidationException::class);
    })->with(['letters' => ['5oo'], 'negative' => ['-500'], 'three decimals' => ['500.123']]);
});
