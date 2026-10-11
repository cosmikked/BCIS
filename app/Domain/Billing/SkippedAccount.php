<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\SkipReason;
use App\Models\ServiceAccount;

/** An account that gets no invoice this period, and why (FR-BIL-002). */
final readonly class SkippedAccount
{
    public function __construct(
        public ServiceAccount $account,
        public SkipReason $reason,
    ) {}
}
