<?php

declare(strict_types=1);

use App\Domain\Billing\DueDateCalculator;
use App\Domain\Billing\InvoiceFigures;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

// FR-BIL-007, INV-06

it('uses the billing due day inside the billed month', function (): void {
    $due = (new DueDateCalculator)->forPeriod(CarbonImmutable::parse('2026-10-01'), 5);

    expect($due->toDateString())->toBe('2026-10-05');
});

it('falls back to the last day of a short month (FR-BIL-007)', function (string $period, int $dueDay, string $expected): void {
    $due = (new DueDateCalculator)->forPeriod(CarbonImmutable::parse($period), $dueDay);

    expect($due->toDateString())->toBe($expected);
})->with([
    'day 31 in November' => ['2026-11-01', 31, '2026-11-30'],
    'day 31 in October' => ['2026-10-01', 31, '2026-10-31'],
    'day 30 in February' => ['2026-02-01', 30, '2026-02-28'],
    'day 29 in a leap February' => ['2028-02-01', 29, '2028-02-29'],
    'day 29 in a common February' => ['2027-02-01', 29, '2027-02-28'],
    'day 31 in April' => ['2026-04-01', 31, '2026-04-30'],
    'day 1' => ['2026-06-01', 1, '2026-06-01'],
]);

it('ignores the day of the month in the period it is given', function (): void {
    $due = (new DueDateCalculator)->forPeriod(CarbonImmutable::parse('2026-11-17'), 31);

    expect($due->toDateString())->toBe('2026-11-30');
});

it('derives the balance as total plus adjustments minus allocations (INV-06)', function (): void {
    $figures = new InvoiceFigures(
        Money::fromCentavos(100000),
        Money::fromCentavos(-10000),
        Money::fromCentavos(30000),
    );

    expect($figures->balance()->centavos)->toBe(60000);
});

it('lets a debit adjustment raise the balance', function (): void {
    $figures = new InvoiceFigures(Money::fromCentavos(100000), Money::fromCentavos(25000), Money::zero());

    expect($figures->balance()->centavos)->toBe(125000);
});
