<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\SkipReason;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\ServiceAccount;
use Carbon\CarbonInterface;

/**
 * FR-BIL-002. An account is billed for a period only when it is ACTIVE, its
 * subscriber is active, and its billing start date is on or before the end of
 * that month (PRD 19 #5: no proration, so the month containing the start date
 * is billed in full). Everything else is skipped WITH a reason, so the preview
 * can say why.
 */
final class BillingEligibility
{
    public function skipReason(ServiceAccount $account, CarbonInterface $period, bool $alreadyBilled): ?SkipReason
    {
        $statusReason = match ($account->status) {
            ServiceStatus::Pending => SkipReason::Pending,
            ServiceStatus::Suspended => SkipReason::Suspended,
            ServiceStatus::Terminated => SkipReason::Terminated,
            ServiceStatus::Active => null,
        };

        if ($statusReason !== null) {
            return $statusReason;
        }

        if ($account->subscriber->status !== SubscriberStatus::Active) {
            return SkipReason::SubscriberInactive;
        }

        if ($account->billing_start_date->greaterThan($period->copy()->endOfMonth())) {
            return SkipReason::NotStarted;
        }

        return $alreadyBilled ? SkipReason::AlreadyBilled : null;
    }
}
