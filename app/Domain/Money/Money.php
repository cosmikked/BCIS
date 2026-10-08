<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use Stringable;

/**
 * An amount of Philippine pesos stored as an integer number of centavos (INV-01).
 *
 * No float is ever used: arithmetic is integer-only, overflow throws instead of
 * silently turning into a float, and pesos text is parsed digit by digit.
 * Display (₱1,499.00) happens only at the edge, through format().
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public const SYMBOL = '₱';

    private function __construct(public int $centavos)
    {
        // PHP_INT_MIN has no positive twin, so abs() and negate() would overflow.
        if ($centavos === PHP_INT_MIN) {
            throw new OverflowException('Money amount is out of range.');
        }
    }

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse text such as "1499", "1499.5", "1,499.00", "₱1,499.00" or "-50.25".
     * More than two decimals is an error: a centavo is the smallest unit and
     * rounding would silently change an amount.
     */
    public static function fromPesos(string $text): self
    {
        $clean = trim(str_replace(' ', '', $text));

        $pattern = '/^(?<sign>-)?(?:'.self::SYMBOL.')?(?<pesos>\d{1,3}(?:,\d{3})+|\d+)(?:\.(?<cents>\d{1,2}))?$/u';

        if (preg_match($pattern, $clean, $m) !== 1) {
            throw new InvalidArgumentException("'{$text}' is not a valid peso amount.");
        }

        $pesos = str_replace(',', '', $m['pesos']);
        $cents = str_pad($m['cents'] ?? '', 2, '0');

        // Reject values that do not fit in an int before they can become floats.
        $digits = ltrim($pesos.$cents, '0');
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new OverflowException('Money amount is out of range.');
        }

        $centavos = (int) ($pesos.$cents);

        return new self($m['sign'] === '-' ? -$centavos : $centavos);
    }

    public function add(self $other): self
    {
        // Check the bounds BEFORE adding: past PHP_INT_MAX an int silently becomes a float.
        $a = $this->centavos;
        $b = $other->centavos;

        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw new OverflowException('Money addition overflowed.');
        }

        return new self($a + $b);
    }

    public function subtract(self $other): self
    {
        $a = $this->centavos;
        $b = $other->centavos;

        if (($b < 0 && $a > PHP_INT_MAX + $b) || ($b > 0 && $a < PHP_INT_MIN + $b)) {
            throw new OverflowException('Money subtraction overflowed.');
        }

        return new self($a - $b);
    }

    public function negate(): self
    {
        return new self(-$this->centavos);
    }

    public function abs(): self
    {
        return new self(abs($this->centavos));
    }

    /** The smaller of the two amounts, for example the payment applied to an invoice. */
    public function min(self $other): self
    {
        return $this->centavos <= $other->centavos ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->centavos >= $other->centavos ? $this : $other;
    }

    /** Returns -1, 0 or 1. */
    public function compareTo(self $other): int
    {
        return $this->centavos <=> $other->centavos;
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    public function greaterThan(self $other): bool
    {
        return $this->centavos > $other->centavos;
    }

    public function lessThan(self $other): bool
    {
        return $this->centavos < $other->centavos;
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function isPositive(): bool
    {
        return $this->centavos > 0;
    }

    public function isNegative(): bool
    {
        return $this->centavos < 0;
    }

    /** Plain decimal text for exports and inputs, for example "1499.00" or "-0.05". */
    public function toDecimalString(): string
    {
        $abs = abs($this->centavos);
        $text = intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $this->centavos < 0 ? '-'.$text : $text;
    }

    /** Display text for the UI and print views, for example "₱1,499.00" or "-₱50.25". */
    public function format(): string
    {
        $abs = abs($this->centavos);
        $pesos = (string) intdiv($abs, 100);
        $cents = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        // Group thousands with string operations; number_format() would convert to float.
        $grouped = strrev(implode(',', str_split(strrev($pesos), 3)));

        return ($this->centavos < 0 ? '-' : '').self::SYMBOL.$grouped.'.'.$cents;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** Serialises as the integer centavos so JSON never carries a float. */
    public function jsonSerialize(): int
    {
        return $this->centavos;
    }
}
