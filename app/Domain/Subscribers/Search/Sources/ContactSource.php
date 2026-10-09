<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search\Sources;

use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\SearchSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phone number (any formatting) or email. A term that looks like neither does
 * not search contacts, so a name never turns up phone matches.
 */
final class ContactSource implements SearchSource
{
    public function label(): string
    {
        return 'Contact';
    }

    public function subscriberIds(string $term): ?Builder
    {
        if (str_contains($term, '@')) {
            $like = '%'.ContactNormalizer::escapeLike(mb_strtolower($term)).'%';

            return DB::table('subscriber_contacts')->select('subscriber_id')->whereRaw('lower(value) like ?', [$like]);
        }

        // 0917-123-4567, +63 917 123 4567 and 09171234567 are the same number.
        if (preg_match('/^[\d\s+()-]+$/', $term) === 1) {
            $digits = ContactNormalizer::phoneDigits($term);

            if (strlen($digits) >= 4) {
                return DB::table('subscriber_contacts')->select('subscriber_id')
                    ->whereRaw("regexp_replace(value, '\\D', '', 'g') like ?", ['%'.$digits.'%']);
            }
        }

        return null;
    }
}
