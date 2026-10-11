<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\DiscardDraftInvoices;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\ServicePlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\BillingData;
use Tests\Support\Users;

// AT-11 (duplicate billing), FR-BIL-003, INV-09. The two-process half of AT-11 is
// tests/Concurrency/DuplicateGenerationTest.php.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $this->accounts = collect(range(1, 4))->map(fn () => BillingData::activeAccount($this->owner, plan: $plan));
});

// The slip-past test listens for Invoice creating events. Remove only that listener afterwards:
// Invoice::flushEventListeners() would also drop the model's own update and delete guards.
afterEach(fn () => Event::forget('eloquent.creating: '.Invoice::class));

/** Non-void MONTHLY invoices per service account for October: every count must be 1. */
function monthlyCounts(): array
{
    return Invoice::query()
        ->where('invoice_type', InvoiceType::Monthly->value)
        ->where('billing_period', '2026-10-01')
        ->where('status', '<>', 'VOID')
        ->selectRaw('service_account_id, COUNT(*) AS n')
        ->groupBy('service_account_id')
        ->pluck('n', 'service_account_id')
        ->map(fn ($n) => (int) $n)
        ->all();
}

it('AT-11: running generation twice leaves one invoice per account', function (): void {
    $generate = app(GenerateBillingCycle::class);

    $first = $generate->handle($this->owner, '2026-10');
    $second = $generate->handle($this->owner, '2026-10');

    expect($first->created)->toBe(4)
        ->and($second->created)->toBe(0)
        ->and($second->alreadyBilled)->toBe(4)
        ->and(monthlyCounts())->toHaveCount(4)
        ->and(array_values(array_unique(monthlyCounts())))->toBe([1])
        ->and(BillingCycle::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(4);
});

it('AT-11: a partly billed month is completed, never doubled', function (): void {
    // One account was billed by an earlier run (as if another PC had just committed it).
    $billed = $this->accounts[0];
    BillingData::invoice($billed, ['status' => 'DRAFT', 'billing_period' => '2026-10-01']);

    $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

    expect($result->created)->toBe(3)
        ->and($result->alreadyBilled)->toBe(1)
        ->and(array_values(array_unique(monthlyCounts())))->toBe([1])
        ->and(monthlyCounts())->toHaveCount(4);
});

it('AT-11: the database rejects a duplicate that slips past the code, and the month still completes', function (): void {
    // While the Action is creating the second draft, "another PC" commits an invoice for the
    // third account. The planner already decided to bill that account, so only the partial
    // unique index (INV-09) stands in the way. It must be treated as "already billed".
    $victim = $this->accounts[2];
    $slipped = false;

    Invoice::creating(function (Invoice $invoice) use ($victim, &$slipped): void {
        if (! $slipped && $invoice->service_account_id === $this->accounts[1]->id) {
            $slipped = true;
            BillingData::invoice($victim, ['status' => 'DRAFT', 'billing_period' => '2026-10-01']);
        }
    });

    $result = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10');

    expect($slipped)->toBeTrue()
        ->and($result->created)->toBe(3)
        ->and($result->alreadyBilled)->toBe(1)
        ->and(array_values(array_unique(monthlyCounts())))->toBe([1])
        ->and(monthlyCounts())->toHaveCount(4)
        ->and(Invoice::query()->where('service_account_id', $victim->id)->count())->toBe(1);
});

it('AT-11: the unique index itself refuses a second monthly invoice, but frees the slot for a void', function (): void {
    $account = $this->accounts[0];
    $first = BillingData::invoice($account, ['status' => 'UNPAID', 'billing_period' => '2026-10-01']);

    DB::statement('SAVEPOINT duplicate');
    expect(fn () => BillingData::invoice($account, ['status' => 'DRAFT', 'billing_period' => '2026-10-01']))
        ->toThrow(UniqueConstraintViolationException::class);
    DB::statement('ROLLBACK TO SAVEPOINT duplicate');

    DB::table('invoices')->where('id', $first)->update(['status' => 'VOID', 'voided_at' => now(), 'void_reason' => 'Test']);
    $replacement = BillingData::invoice($account, ['status' => 'DRAFT', 'billing_period' => '2026-10-01']);

    expect($replacement)->toBeGreaterThan($first);
});

it('AT-11: a finalized month cannot be generated or discarded again', function (): void {
    $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
    app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

    expect(fn () => app(GenerateBillingCycle::class)->handle($this->owner, '2026-10'))->toThrow(ValidationException::class)
        ->and(fn () => app(DiscardDraftInvoices::class)->handle($this->owner, $cycle->id, 'Try to start over'))->toThrow(ValidationException::class)
        ->and(Invoice::query()->where('status', InvoiceStatus::Unpaid->value)->count())->toBe(4)
        ->and(array_values(array_unique(monthlyCounts())))->toBe([1]);
});
