<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;

// INV-01

it('stores integer centavos', function (): void {
    expect(Money::fromCentavos(149900)->centavos)->toBe(149900)
        ->and(Money::zero()->centavos)->toBe(0);
});

it('rejects a float amount', function (): void {
    expect(fn () => Money::fromCentavos(1499.5))->toThrow(TypeError::class);
});

it('adds and subtracts exactly', function (): void {
    $a = Money::fromCentavos(10);
    $b = Money::fromCentavos(20);

    expect($a->add($b)->centavos)->toBe(30)
        ->and($b->subtract($a)->centavos)->toBe(10)
        ->and($a->subtract($b)->centavos)->toBe(-10)
        ->and($a->negate()->centavos)->toBe(-10);
});

it('has no 0.1 + 0.2 style drift', function (): void {
    $sum = Money::fromPesos('0.10')->add(Money::fromPesos('0.20'));

    expect($sum->centavos)->toBe(30)
        ->and($sum->equals(Money::fromPesos('0.30')))->toBeTrue();
});

it('throws instead of overflowing into a float', function (): void {
    expect(fn () => Money::fromCentavos(PHP_INT_MAX)->add(Money::fromCentavos(1)))->toThrow(OverflowException::class)
        ->and(fn () => Money::fromCentavos(-PHP_INT_MAX)->subtract(Money::fromCentavos(2)))->toThrow(OverflowException::class)
        ->and(fn () => Money::fromCentavos(PHP_INT_MIN))->toThrow(OverflowException::class);
});

it('formats pesos with the symbol, grouping and two decimals', function (int $centavos, string $expected): void {
    expect(Money::fromCentavos($centavos)->format())->toBe($expected);
})->with([
    [0, '₱0.00'],
    [5, '₱0.05'],
    [100, '₱1.00'],
    [99900, '₱999.00'],
    [149900, '₱1,499.00'],
    [123456789, '₱1,234,567.89'],
    [-5025, '-₱50.25'],
    [-100000000, '-₱1,000,000.00'],
]);

it('formats a plain decimal string for exports', function (): void {
    expect(Money::fromCentavos(149900)->toDecimalString())->toBe('1499.00')
        ->and(Money::fromCentavos(-5)->toDecimalString())->toBe('-0.05');
});

it('parses peso text into centavos', function (string $text, int $centavos): void {
    expect(Money::fromPesos($text)->centavos)->toBe($centavos);
})->with([
    ['1499', 149900],
    ['1499.5', 149950],
    ['1,499.00', 149900],
    ['₱1,499.00', 149900],
    ['0.05', 5],
    ['-50.25', -5025],
    [' 12 ', 1200],
    ['0', 0],
]);

it('rejects text it cannot read exactly', function (string $text): void {
    expect(fn () => Money::fromPesos($text))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '1.234', '1,49.00', '1e3', '12.', '.5', '--5', '1 000 000x']);

it('rejects text too large for an integer', function (): void {
    expect(fn () => Money::fromPesos('99999999999999999999'))->toThrow(OverflowException::class);
});

it('compares amounts', function (): void {
    $small = Money::fromCentavos(100);
    $big = Money::fromCentavos(200);

    expect($small->compareTo($big))->toBe(-1)
        ->and($big->compareTo($small))->toBe(1)
        ->and($small->compareTo(Money::fromCentavos(100)))->toBe(0)
        ->and($small->lessThan($big))->toBeTrue()
        ->and($big->greaterThan($small))->toBeTrue()
        ->and($small->min($big)->centavos)->toBe(100)
        ->and($small->max($big)->centavos)->toBe(200)
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($small->isPositive())->toBeTrue()
        ->and($small->negate()->isNegative())->toBeTrue();
});

it('serialises to JSON as an integer', function (): void {
    expect(json_encode(['total' => Money::fromCentavos(149900)]))->toBe('{"total":149900}');
});

it('casts between bigint and Money for Eloquent', function (): void {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->get($model, 'total_centavos', 149900, [])->centavos)->toBe(149900)
        ->and($cast->get($model, 'total_centavos', '149900', [])->centavos)->toBe(149900)
        ->and($cast->get($model, 'total_centavos', null, []))->toBeNull()
        ->and($cast->set($model, 'total_centavos', Money::fromCentavos(5), []))->toBe(5)
        ->and(fn () => $cast->set($model, 'total_centavos', 12.5, []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $cast->set($model, 'total_centavos', '12.50', []))->toThrow(InvalidArgumentException::class);
});
