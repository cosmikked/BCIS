<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Subscribers\Search\SearchSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds the subscriber who owns an invoice number (FR-SUB-003, FR-BIL-013).
 * It answers only for terms that look like an invoice number ("INV-000123",
 * "inv000123" or just "000123"), so an ordinary name search never scans invoices.
 * Drafts have no number and are never matched.
 */
final class InvoiceNumberSource implements SearchSource
{
    public function label(): string
    {
        return 'Invoice number';
    }

    public function subscriberIds(string $term): ?Builder
    {
        $digits = self::digitsOf($term);

        if ($digits === null) {
            return null;
        }

        return DB::table('invoices')->select('subscriber_id')
            ->whereNotNull('invoice_no')
            ->where('invoice_no', 'ilike', '%'.$digits.'%');
    }

    /** The number part of an invoice-number-shaped term, or null when the term is not shaped like one. */
    public static function digitsOf(string $term): ?string
    {
        $prefix = rtrim(strtolower((string) config('bcis.document_numbers.invoice_prefix')), '-');

        if (preg_match('/^(?:'.preg_quote($prefix, '/').'-?)?(\d{2,})$/i', trim($term), $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
