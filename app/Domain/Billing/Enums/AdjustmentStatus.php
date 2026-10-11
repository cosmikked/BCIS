<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** PRD 19 #8: whoever requests an adjustment is never the one who approves it. */
enum AdjustmentStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
