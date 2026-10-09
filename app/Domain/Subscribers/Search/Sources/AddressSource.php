<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search\Sources;

use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\SearchSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Substring search over street, barangay, city and landmark. The expression is
 * the one in the subscriber_addresses_text_trgm index (migration 000007); change
 * both together or the index stops being used.
 */
final class AddressSource implements SearchSource
{
    public function label(): string
    {
        return 'Address';
    }

    public function subscriberIds(string $term): ?Builder
    {
        if (mb_strlen($term) < 3) {
            return null;
        }

        $like = '%'.ContactNormalizer::escapeLike(mb_strtolower($term)).'%';

        return DB::table('subscriber_addresses')->select('subscriber_id')
            ->whereRaw("lower(line1 || ' ' || coalesce(barangay, '') || ' ' || city || ' ' || coalesce(landmark, '')) like ?", [$like]);
    }
}
