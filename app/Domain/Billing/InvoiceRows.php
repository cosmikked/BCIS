<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Adds the balance and the derived OVERDUE flag to a page of invoices, for the
 * Invoices list and the subscriber's Billing tab. The balances of the whole
 * page are read in two queries, not one per row (NFR-02).
 */
final class InvoiceRows
{
    public function __construct(
        private readonly InvoiceBalanceCalculator $balances,
        private readonly InvoiceStatusResolver $statuses,
    ) {}

    /**
     * @param  iterable<Invoice>  $invoices
     * @return Collection<int, InvoiceRow>
     */
    public function for(iterable $invoices): Collection
    {
        $invoices = collect($invoices)->values();
        $figures = $this->balances->figures($invoices);

        return $invoices->map(function (Invoice $invoice) use ($figures): InvoiceRow {
            $balance = $figures[$invoice->id]->balance();

            return new InvoiceRow(
                invoice: $invoice,
                balance: $balance,
                overdue: $this->statuses->isOverdue($invoice->status, $invoice->due_date, $balance),
                daysPastDue: $this->statuses->daysPastDue($invoice->due_date),
            );
        });
    }
}
