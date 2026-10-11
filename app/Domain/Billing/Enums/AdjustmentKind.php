<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use App\Domain\Security\Permission;

/** FR-BIL-009. A void is the VOID kind: it cancels the whole outstanding balance. */
enum AdjustmentKind: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
    case WriteOff = 'WRITE_OFF';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit adjustment',
            self::Credit => 'Credit adjustment',
            self::WriteOff => 'Write-off',
            self::Void => 'Void invoice',
        };
    }

    /** A debit raises what the subscriber owes. Every other kind lowers it. */
    public function increasesBalance(): bool
    {
        return $this === self::Debit;
    }

    /** The permission needed to ask for, and to approve, this kind. */
    public function permission(): Permission
    {
        return $this === self::Void ? Permission::InvoiceVoid : Permission::InvoiceAdjust;
    }
}
