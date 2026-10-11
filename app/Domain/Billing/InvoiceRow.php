<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Money\Money;
use App\Models\Invoice;

/** One invoice as a list shows it: the balance and the derived overdue flag come with it. */
final readonly class InvoiceRow
{
    public function __construct(
        public Invoice $invoice,
        public Money $balance,
        public bool $overdue,
        public int $daysPastDue,
    ) {}
}
