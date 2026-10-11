<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\AllocationTotals;
use App\Domain\Money\Money;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * INV-06. An invoice balance is always derived, never stored:
 *   balance = total + approved adjustments - net allocations.
 * Pending and rejected adjustments do not count. The Actions refuse any change
 * that would push the balance below zero; this class reports what it finds, so
 * an integrity check can still see a negative balance if one ever appeared.
 *
 * A DRAFT invoice has no ledger entry yet, so its "balance" is only its total.
 * The lists use figures() to read many invoices in two queries, not one each.
 */
final class InvoiceBalanceCalculator
{
    public function __construct(private readonly AllocationTotals $allocations) {}

    public function balance(Invoice $invoice): Money
    {
        return $this->figuresFor($invoice)->balance();
    }

    public function figuresFor(Invoice $invoice): InvoiceFigures
    {
        return $this->figures([$invoice])[$invoice->id];
    }

    /**
     * @param  iterable<Invoice>  $invoices
     * @return array<int, InvoiceFigures> keyed by invoice id
     */
    public function figures(iterable $invoices): array
    {
        $totals = [];
        foreach ($invoices as $invoice) {
            $totals[$invoice->id] = $invoice->total_centavos;
        }

        if ($totals === []) {
            return [];
        }

        $ids = array_keys($totals);
        $adjustments = $this->approvedAdjustments($ids);
        $allocated = $this->allocations->forInvoices($ids);

        $figures = [];
        foreach ($totals as $id => $total) {
            $figures[$id] = new InvoiceFigures($total, $adjustments[$id] ?? Money::zero(), $allocated[$id] ?? Money::zero());
        }

        return $figures;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, Money>
     */
    private function approvedAdjustments(array $invoiceIds): array
    {
        $rows = DB::table('adjustments')
            ->where('status', 'APPROVED')
            ->whereIn('invoice_id', $invoiceIds)
            ->groupBy('invoice_id')
            ->selectRaw("invoice_id, SUM(CASE WHEN kind = 'DEBIT' THEN amount_centavos ELSE -amount_centavos END) AS net")
            ->get();

        $net = [];
        foreach ($rows as $row) {
            $net[(int) $row->invoice_id] = Money::fromCentavos((int) $row->net);
        }

        return $net;
    }
}
