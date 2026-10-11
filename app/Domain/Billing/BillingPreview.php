<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Models\BillingCycle;

/** What the Generate Billing screen shows before anything is saved (FR-BIL-001). */
final readonly class BillingPreview
{
    public function __construct(
        public BillingPlan $plan,
        /** The month's cycle if one exists (DRAFT or FINALIZED), else null. */
        public ?BillingCycle $cycle,
    ) {}

    /** A FINALIZED month cannot be generated or regenerated (FR-BIL-004). */
    public function isFinalized(): bool
    {
        return $this->cycle?->status === BillingCycleStatus::Finalized;
    }
}
