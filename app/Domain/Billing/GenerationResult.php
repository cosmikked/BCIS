<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\BillingCycle;

/** Outcome of one Generate run. A second run reports everything as already billed (AT-11). */
final readonly class GenerationResult
{
    public function __construct(
        public BillingCycle $cycle,
        public int $created,
        public int $alreadyBilled,
        public int $ineligible,
    ) {}
}
