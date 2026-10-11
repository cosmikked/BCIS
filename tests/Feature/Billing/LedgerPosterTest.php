<?php

declare(strict_types=1);

use App\Domain\Billing\DocumentNumberGenerator;
use App\Domain\Billing\Enums\LedgerEntryType;
use App\Domain\Billing\LedgerPoster;
use App\Domain\Billing\LedgerPosting;
use App\Domain\Money\Money;
use App\Domain\Security\RoleCode;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-BIL-013, FR-BIL-014, INV-02, INV-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->account = SubscriberData::account($this->owner);
});

/** @param  array<string, mixed>  $with */
function ledgerPostingFor(object $test, int $debit, int $credit, array $with = []): LedgerPosting
{
    return new LedgerPosting(...[...[
        'type' => LedgerEntryType::Invoice,
        'subscriberId' => $test->account->subscriber_id,
        'serviceAccountId' => $test->account->id,
        'entryDate' => CarbonImmutable::parse('2026-10-01'),
        'debit' => Money::fromCentavos($debit),
        'credit' => Money::fromCentavos($credit),
        'description' => 'October Internet',
        'referenceType' => 'invoice',
        'referenceId' => 1,
        'referenceNo' => 'INV-000001',
        'actor' => $test->owner,
    ], ...$with]);
}

it('posts a debit with its reference and actor', function (): void {
    $entry = app(LedgerPoster::class)->post(ledgerPostingFor($this, 99900, 0));

    expect($entry)->toBeInstanceOf(LedgerEntry::class)
        ->and($entry->debit_centavos->centavos)->toBe(99900)
        ->and($entry->credit_centavos->centavos)->toBe(0)
        ->and($entry->entry_type)->toBe(LedgerEntryType::Invoice)
        ->and($entry->entry_date->toDateString())->toBe('2026-10-01')
        ->and($entry->reference_no)->toBe('INV-000001')
        ->and($entry->created_by)->toBe($this->owner->id)
        ->and($entry->subscriber_id)->toBe($this->account->subscriber_id);
});

it('stamps posted_at on the server', function (): void {
    $entry = app(LedgerPoster::class)->post(ledgerPostingFor($this, 100, 0, ['referenceId' => 2]))->refresh();

    expect($entry->posted_at)->not->toBeNull();
});

it('posts a credit', function (): void {
    $entry = app(LedgerPoster::class)->post(ledgerPostingFor($this, 0, 5000, [
        'type' => LedgerEntryType::Adjustment, 'referenceType' => 'adjustment', 'referenceNo' => 'ADJ-000001',
    ]));

    expect($entry->credit_centavos->centavos)->toBe(5000)
        ->and($entry->debit_centavos->centavos)->toBe(0);
});

it('refuses an entry that is not exactly one positive amount', function (int $debit, int $credit): void {
    expect(fn () => app(LedgerPoster::class)->post(ledgerPostingFor($this, $debit, $credit)))
        ->toThrow(InvalidArgumentException::class);

    expect(LedgerEntry::query()->count())->toBe(0);
})->with([
    'both sides' => [100, 100],
    'zero' => [0, 0],
    'negative debit' => [-100, 0],
    'negative credit' => [0, -100],
]);

it('never posts the same invoice twice, even if the code ran twice', function (): void {
    app(LedgerPoster::class)->post(ledgerPostingFor($this, 99900, 0));

    expect(fn () => DB::transaction(fn () => app(LedgerPoster::class)->post(ledgerPostingFor($this, 99900, 0))))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(LedgerEntry::query()->count())->toBe(1);
});

it('refuses to update or delete an entry through the model', function (): void {
    $entry = app(LedgerPoster::class)->post(ledgerPostingFor($this, 99900, 0));

    expect(fn () => $entry->update(['description' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

describe('document numbers (FR-BIL-013, INV-10)', function (): void {
    it('formats prefix plus a zero-padded sequence value', function (): void {
        $number = app(DocumentNumberGenerator::class)->nextInvoiceNo();

        expect($number)->toMatch('/^INV-\d{6}$/');
        expect(app(DocumentNumberGenerator::class)->nextAdjustmentNo())->toMatch('/^ADJ-\d{6}$/');
    });

    it('always moves forward and skips a number whose transaction rolled back', function (): void {
        $generator = app(DocumentNumberGenerator::class);
        $first = $generator->nextInvoiceNo();

        try {
            DB::transaction(function () use ($generator): void {
                $generator->nextInvoiceNo();
                throw new RuntimeException('forced failure');
            });
        } catch (RuntimeException) {
        }

        $third = $generator->nextInvoiceNo();

        // The rolled-back number is never handed out again: a gap, not a reuse.
        expect((int) substr($third, 4) - (int) substr($first, 4))->toBe(2);
    });

    it('reads the prefix from configuration', function (): void {
        config(['bcis.document_numbers.invoice_prefix' => 'BILL-']);

        expect(app(DocumentNumberGenerator::class)->nextInvoiceNo())->toStartWith('BILL-');
    });
});
