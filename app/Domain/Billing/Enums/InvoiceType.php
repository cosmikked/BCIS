<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** FR-BIL-010. ONE_OFF invoices are outside the monthly uniqueness rule (INV-09). */
enum InvoiceType: string
{
    case Monthly = 'MONTHLY';
    case OneOff = 'ONE_OFF';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::OneOff => 'One-off',
        };
    }
}
