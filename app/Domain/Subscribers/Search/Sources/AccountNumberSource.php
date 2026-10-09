<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search\Sources;

use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\SearchSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Subscriber account number or any of the subscriber's service account numbers. */
final class AccountNumberSource implements SearchSource
{
    public function label(): string
    {
        return 'Account number';
    }

    public function subscriberIds(string $term): Builder
    {
        $like = '%'.ContactNormalizer::escapeLike($term).'%';

        return DB::table('subscribers')->select('id')->where(function (Builder $query) use ($like): void {
            $query->where('account_no', 'ilike', $like)
                ->orWhereIn('id', DB::table('service_accounts')->select('subscriber_id')->where('account_no', 'ilike', $like));
        });
    }
}
