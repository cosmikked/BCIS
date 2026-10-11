<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * FR-BIL-007. The due date is the account's billing due day inside the billed
 * month. When the month is shorter than the due day, it falls back to the last
 * day of that month (due day 31 in November gives November 30).
 */
final class DueDateCalculator
{
    public function forPeriod(CarbonInterface $period, int $dueDay): CarbonImmutable
    {
        $first = CarbonImmutable::create($period->year, $period->month, 1)->startOfDay();

        return $first->day(min(max($dueDay, 1), $first->daysInMonth));
    }
}
