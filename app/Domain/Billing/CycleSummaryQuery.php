<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\SettingsRepository;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The numbers on the Current Billing screen for one billing cycle (FR-BIL-014):
 * invoices by stored status, how many are overdue (derived, never stored) and
 * what is still owed. Counts come from grouped queries, not from loading rows.
 */
final class CycleSummaryQuery
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly InvoiceBalanceCalculator $balances,
    ) {}

    /** @return array<string, int> InvoiceStatus value => invoice count; every status is present */
    public function statusCounts(int $billingCycleId): array
    {
        $counts = array_fill_keys(array_map(fn (InvoiceStatus $s) => $s->value, InvoiceStatus::cases()), 0);

        $rows = Invoice::query()
            ->where('billing_cycle_id', $billingCycleId)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->n;
        }

        return $counts;
    }

    /** Collectible invoices of the cycle whose due date plus grace is already past. */
    public function overdueCount(int $billingCycleId): int
    {
        $cutoff = CarbonImmutable::today()->subDays($this->settings->int('billing.grace_days'));

        return Invoice::query()
            ->where('billing_cycle_id', $billingCycleId)
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<', $cutoff->toDateString())
            ->count();
    }

    /**
     * Still owed on the cycle's finalized invoices: totals plus approved
     * adjustments minus allocations. Computed in bulk for the whole cycle.
     */
    public function outstanding(int $billingCycleId): Money
    {
        $owed = Money::zero();

        Invoice::query()
            ->where('billing_cycle_id', $billingCycleId)
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->chunkById(500, function ($invoices) use (&$owed): void {
                foreach ($this->balances->figures($invoices) as $figures) {
                    $owed = $owed->add($figures->balance()->max(Money::zero()));
                }
            });

        return $owed;
    }
}
