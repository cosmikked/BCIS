<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search;

use Illuminate\Database\Query\Builder;

/**
 * One place a search term can match (FR-SUB-003). A source answers one question:
 * which subscriber ids match this term? GlobalSearch combines the sources for
 * the search box and for the subscriber list.
 *
 * Invoice number, receipt number and GCash reference sources are added by the
 * phases that create those tables: write one class implementing this interface
 * and add it to the list in AppServiceProvider. Nothing else changes.
 */
interface SearchSource
{
    /** Shown to the user as "Matched on ...". */
    public function label(): string;

    /**
     * A query that selects one column of subscriber ids, or null when the term
     * cannot match this source at all (a name is never a phone number).
     * The term is already trimmed and at least two characters long.
     */
    public function subscriberIds(string $term): ?Builder;
}
