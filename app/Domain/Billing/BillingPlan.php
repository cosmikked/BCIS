<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\SkipReason;
use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

/** Who is billed and who is skipped for one period. The result of BillingPlanner. */
final readonly class BillingPlan
{
    /**
     * @param  list<BillingLine>  $lines
     * @param  list<SkippedAccount>  $skipped
     */
    public function __construct(
        public CarbonImmutable $period,
        public array $lines,
        public array $skipped,
    ) {}

    public function total(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, BillingLine $line) => $sum->add($line->total()), Money::zero());
    }

    /** @return array<string, int> skip reason value => number of accounts */
    public function skippedByReason(): array
    {
        $counts = [];
        foreach ($this->skipped as $skipped) {
            $counts[$skipped->reason->value] = ($counts[$skipped->reason->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function skippedCount(SkipReason $reason): int
    {
        return $this->skippedByReason()[$reason->value] ?? 0;
    }
}
