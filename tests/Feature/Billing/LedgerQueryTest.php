<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\LedgerQuery;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-016, INV-02, TO-05

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->account = BillingData::activeAccount($this->owner);
    $this->other = BillingData::activeAccount($this->owner);

    // Posted out of date order on purpose: the 09-01 entry has the HIGHEST id.
    BillingData::ledger($this->account, $this->owner->id, ['entry_date' => '2026-08-01', 'debit_centavos' => 100000]);
    BillingData::ledger($this->account, $this->owner->id, ['entry_date' => '2026-10-01', 'debit_centavos' => 100000]);
    BillingData::ledger($this->account, $this->owner->id, [
        'entry_date' => '2026-09-01', 'entry_type' => 'PAYMENT', 'debit_centavos' => 0, 'credit_centavos' => 30000,
    ]);
    // Another subscriber's entry must never leak in.
    BillingData::ledger($this->other, $this->owner->id, ['entry_date' => '2026-08-15', 'debit_centavos' => 77700]);
});

it('orders by entry date then id and computes the running balance from all earlier entries', function (): void {
    $statement = app(LedgerQuery::class)->statement($this->account->subscriber_id);

    expect($statement->totalRows)->toBe(3)
        ->and(array_map(fn ($r) => $r->entryDate->toDateString(), $statement->rows))->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and(array_map(fn ($r) => $r->runningBalance->centavos, $statement->rows))->toBe([100000, 70000, 170000])
        ->and($statement->rows[1]->type)->toBe(LedgerEntryType::Payment)
        ->and($statement->rows[1]->credit->centavos)->toBe(30000)
        ->and($statement->openingBalance->centavos)->toBe(0)
        ->and($statement->closingBalance->centavos)->toBe(170000);
});

it('keeps the true running balance when a date filter hides earlier lines', function (): void {
    $statement = app(LedgerQuery::class)->statement(
        $this->account->subscriber_id,
        from: CarbonImmutable::parse('2026-09-01'),
        to: CarbonImmutable::parse('2026-09-30'),
    );

    expect($statement->totalRows)->toBe(1)
        ->and($statement->rows[0]->runningBalance->centavos)->toBe(70000)
        ->and($statement->openingBalance->centavos)->toBe(100000)
        ->and($statement->closingBalance->centavos)->toBe(70000);

    // Opening balance plus the lines in range equals the closing balance.
    $moved = array_reduce($statement->rows, fn (Money $sum, $r) => $sum->add($r->debit)->subtract($r->credit), Money::zero());
    expect($statement->openingBalance->add($moved)->equals($statement->closingBalance))->toBeTrue();
});

it('includes both end dates of the range', function (): void {
    $statement = app(LedgerQuery::class)->statement(
        $this->account->subscriber_id,
        from: CarbonImmutable::parse('2026-08-01'),
        to: CarbonImmutable::parse('2026-10-01'),
    );

    expect($statement->totalRows)->toBe(3);
});

it('pages without disturbing the balances', function (): void {
    $second = app(LedgerQuery::class)->statement($this->account->subscriber_id, perPage: 2, page: 2);

    expect($second->totalRows)->toBe(3)
        ->and($second->rows)->toHaveCount(1)
        ->and($second->rows[0]->runningBalance->centavos)->toBe(170000);
});

it('shows an empty ledger as zero (TO-05)', function (): void {
    $empty = BillingData::activeAccount($this->owner);
    $q = app(LedgerQuery::class);

    expect($q->statement($empty->subscriber_id)->rows)->toBe([])
        ->and($q->currentBalance($empty->subscriber_id)->centavos)->toBe(0)
        ->and($q->lastRunningBalance($empty->subscriber_id))->toBeNull();
});

it('agrees with the plain sum for every subscriber (TO-05)', function (): void {
    $q = app(LedgerQuery::class);

    foreach ([$this->account, $this->other] as $account) {
        expect($q->lastRunningBalance($account->subscriber_id)?->centavos)->toBe($q->currentBalance($account->subscriber_id)->centavos);
    }

    expect($q->currentBalance($this->other->subscriber_id)->centavos)->toBe(77700);
});
