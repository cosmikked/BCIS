<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Invoice and adjustment numbers (FR-BIL-013, INV-10). Prefix plus a zero-padded
 * PostgreSQL sequence value, never max + 1. A number drawn by a transaction that
 * later rolls back is simply skipped: gaps are fine, reuse is not. The unique
 * index on each number column is the final duplicate guard. Phase 5 adds the
 * receipt and reversal numbers here.
 */
final class DocumentNumberGenerator
{
    public function nextInvoiceNo(): string
    {
        return $this->format((string) config('bcis.document_numbers.invoice_prefix'), $this->next('invoice_no_seq'));
    }

    public function nextAdjustmentNo(): string
    {
        return $this->format((string) config('bcis.document_numbers.adjustment_prefix'), $this->next('adjustment_no_seq'));
    }

    /** $sequence is one of the two constants above, never user input. */
    private function next(string $sequence): int
    {
        $row = DB::selectOne("SELECT nextval('{$sequence}') AS n");

        return (int) $row->n;
    }

    private function format(string $prefix, int $value): string
    {
        $digits = (int) config('bcis.document_numbers.digits', 6);

        return $prefix.str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }
}
