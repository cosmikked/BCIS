<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** FR-BIL-006. A DISCOUNT is stored as a negative amount, every other type as a positive one. */
enum InvoiceItemType: string
{
    case Subscription = 'SUBSCRIPTION';
    case Installation = 'INSTALLATION';
    case Reconnection = 'RECONNECTION';
    case Discount = 'DISCOUNT';
    case Penalty = 'PENALTY';
    case Other = 'OTHER';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
