<?php

declare(strict_types=1);

namespace App\Domain\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast for *_centavos columns: bigint in the database, Money in PHP.
 *
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return Money::fromCentavos($value);
        }

        // Some drivers return bigint as a numeric string.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return Money::fromCentavos((int) $value);
        }

        throw new InvalidArgumentException("Column '{$key}' does not hold an integer centavo amount.");
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        // Only Money is accepted, so a float or an untyped string can never be stored.
        if (! $value instanceof Money) {
            throw new InvalidArgumentException("Column '{$key}' only accepts a Money value.");
        }

        return $value->centavos;
    }
}
