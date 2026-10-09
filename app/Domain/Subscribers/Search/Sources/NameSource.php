<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search\Sources;

use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\SearchSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Name search with the pg_trgm index on lower(full_name): a substring match, or
 * a fuzzy match (the % operator) so a small typo still finds the person. The
 * expression must stay lower(full_name) to use subscribers_full_name_trgm.
 */
final class NameSource implements SearchSource
{
    public function label(): string
    {
        return 'Name';
    }

    public function subscriberIds(string $term): Builder
    {
        $lower = mb_strtolower($term);
        $like = '%'.ContactNormalizer::escapeLike($lower).'%';

        return DB::table('subscribers')->select('id')->where(function (Builder $query) use ($like, $lower): void {
            $query->whereRaw('lower(full_name) like ?', [$like]);

            // Trigrams need at least three characters to say anything useful.
            if (mb_strlen($lower) >= 3) {
                $query->orWhereRaw('lower(full_name) % ?', [$lower]);
            }
        });
    }
}
