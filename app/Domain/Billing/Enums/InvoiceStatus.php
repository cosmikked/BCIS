<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * FR-BIL-008. OVERDUE is deliberately not a case: it is derived from the due
 * date, the grace period and the balance, never stored (InvoiceStatusResolver).
 */
enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Unpaid = 'UNPAID';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Void = 'VOID';
    case Credited = 'CREDITED';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially paid',
            default => ucfirst(strtolower($this->value)),
        };
    }

    /** A numbered invoice: it has a number and a ledger debit, and is immutable (INV-05). */
    public function isFinalized(): bool
    {
        return $this !== self::Draft;
    }

    /** Statuses that can still be collected, so they can be overdue. */
    public function isCollectible(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }
}
