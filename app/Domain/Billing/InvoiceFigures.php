<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Money\Money;

/**
 * The three numbers an invoice balance is made of (INV-06):
 * balance = total + adjustments - allocations.
 */
final readonly class InvoiceFigures
{
    /**
     * @param  Money  $adjustments  approved adjustments, signed: a debit is positive, a credit, write-off or void negative
     * @param  Money  $allocated  net payments applied to the invoice
     */
    public function __construct(
        public Money $total,
        public Money $adjustments,
        public Money $allocated,
    ) {}

    public function balance(): Money
    {
        return $this->total->add($this->adjustments)->subtract($this->allocated);
    }
}
