<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** INV-02. Payment types are written by Phase 5 through the same LedgerPoster. */
enum LedgerEntryType: string
{
    case Invoice = 'INVOICE';
    case Payment = 'PAYMENT';
    case PaymentReversal = 'PAYMENT_REVERSAL';
    case Adjustment = 'ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::PaymentReversal => 'Payment reversal',
            default => ucfirst(strtolower($this->value)),
        };
    }
}
