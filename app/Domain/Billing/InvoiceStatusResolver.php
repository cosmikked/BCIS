<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Money\Money;
use App\Domain\Security\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * FR-BIL-008, INV-06. The stored status follows the balance and is recomputed in
 * the same transaction as every change to it. OVERDUE is never stored: it is
 * derived here from the due date, the grace period and the balance, so changing
 * the grace setting changes every list at once with no data rewrite
 * (FR-REC-006).
 */
final class InvoiceStatusResolver
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** The status an invoice should have, given its current one and its figures. */
    public function statusFor(InvoiceStatus $current, InvoiceFigures $figures): InvoiceStatus
    {
        // A draft has no balance to follow, and a void is final.
        if ($current === InvoiceStatus::Draft || $current === InvoiceStatus::Void) {
            return $current;
        }

        if (! $figures->balance()->isPositive()) {
            // Settled by payments, or wiped out by a credit/write-off with no payment.
            return $figures->allocated->isPositive() ? InvoiceStatus::Paid : InvoiceStatus::Credited;
        }

        return $figures->allocated->isPositive() ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Unpaid;
    }

    /**
     * Overdue once today is past the due date plus the grace period, and only
     * while something is still owed on a collectible invoice.
     */
    public function isOverdue(InvoiceStatus $status, CarbonInterface $dueDate, Money $balance, ?CarbonInterface $today = null): bool
    {
        if (! $status->isCollectible() || ! $balance->isPositive()) {
            return false;
        }

        $graceEnds = CarbonImmutable::instance($dueDate)->startOfDay()->addDays($this->settings->int('billing.grace_days'));

        return $this->today($today)->greaterThan($graceEnds);
    }

    /** Whole days since the due date (0 when not yet due). The aging buckets of Phase 8 build on this. */
    public function daysPastDue(CarbonInterface $dueDate, ?CarbonInterface $today = null): int
    {
        $due = CarbonImmutable::instance($dueDate)->startOfDay();
        $today = $this->today($today);

        return $today->greaterThan($due) ? (int) $due->diffInDays($today) : 0;
    }

    private function today(?CarbonInterface $today): CarbonImmutable
    {
        // The app time zone is Asia/Manila (INV-22), so "today" is the business date.
        return $today !== null ? CarbonImmutable::instance($today)->startOfDay() : CarbonImmutable::today();
    }
}
