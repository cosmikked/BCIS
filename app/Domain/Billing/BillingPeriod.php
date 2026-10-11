<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A billing period is a calendar month, identified by its first day
 * (billing_cycles.period_month, invoices.billing_period).
 */
final class BillingPeriod
{
    /**
     * Normalises a date or 'Y-m' / 'Y-m-d' string to the first day of its month.
     *
     * @throws ValidationException
     */
    public static function firstOfMonth(CarbonInterface|string $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::create($value->year, $value->month, 1)->startOfDay();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', strlen($value) === 7 ? $value.'-01' : $value);
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed === null) {
            throw ValidationException::withMessages(['period' => 'Choose a valid billing month.']);
        }

        return self::firstOfMonth($parsed);
    }

    /**
     * A month can be billed from its first day, not before. PRD 19 has no rule
     * for this, so the default (flagged in the commit) is: nothing later than the
     * current month, which stops a mistyped future month creating invoices.
     *
     * @throws ValidationException
     */
    public static function billable(CarbonInterface|string $value): CarbonImmutable
    {
        $period = self::firstOfMonth($value);

        if ($period->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'A month that has not started yet cannot be billed.']);
        }

        return $period;
    }
}
