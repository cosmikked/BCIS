<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** FR-BIL-004. Only a DRAFT cycle can be discarded and regenerated. */
enum BillingCycleStatus: string
{
    case Draft = 'DRAFT';
    case Finalized = 'FINALIZED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
