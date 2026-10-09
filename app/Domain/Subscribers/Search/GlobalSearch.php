<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Global search (FR-SUB-003). It holds no SQL of its own: each SearchSource
 * says which subscriber ids match, and this class combines them. Livewire
 * components call this class and never build search queries themselves.
 *
 * Results are paged on the server by the caller (the list uses
 * applyTo() on its own paginated query; search() returns a short top list).
 */
final class GlobalSearch
{
    public const MIN_LENGTH = 2;

    /** @param  iterable<SearchSource>  $sources */
    public function __construct(private readonly iterable $sources) {}

    /** Trims and collapses whitespace; the form every caller should pass on. */
    public static function clean(?string $term): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $term));
    }

    public static function isSearchable(string $term): bool
    {
        return mb_strlen($term) >= self::MIN_LENGTH;
    }

    /**
     * Narrows a subscriber query to the rows any source matches. A term that is
     * too short is ignored (no filter); a term no source can match returns nothing.
     * The caller is responsible for authorizing subscriber.view.
     *
     * @param  Builder<Subscriber>  $query
     */
    public function applyTo(Builder $query, string $term): void
    {
        $term = self::clean($term);

        if (! self::isSearchable($term)) {
            return;
        }

        $matched = false;

        $query->where(function (Builder $inner) use ($term, &$matched): void {
            foreach ($this->sources as $source) {
                $ids = $source->subscriberIds($term);

                if ($ids !== null) {
                    $matched = true;
                    $inner->orWhereIn('subscribers.id', $ids);
                }
            }
        });

        if (! $matched) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * Top matches for the search box, one entry per subscriber, listing every
     * source that matched. Sources are tried in registration order, so a hit on
     * an account number comes before a hit on a name.
     *
     * @return list<SearchResult>
     */
    public function search(User $actor, string $term, int $limit = 20): array
    {
        Gate::forUser($actor)->authorize('subscriber.view');

        $term = self::clean($term);

        if (! self::isSearchable($term)) {
            return [];
        }

        /** @var array<int, array{subscriber: Subscriber, labels: list<string>}> $found */
        $found = [];

        foreach ($this->sources as $source) {
            $ids = $source->subscriberIds($term);

            if ($ids === null) {
                continue;
            }

            $subscribers = Subscriber::query()->whereIn('id', $ids)->orderBy('full_name')->limit($limit)->get();

            foreach ($subscribers as $subscriber) {
                $found[$subscriber->id] ??= ['subscriber' => $subscriber, 'labels' => []];
                $found[$subscriber->id]['labels'][] = $source->label();
            }
        }

        $results = [];
        foreach (array_slice($found, 0, $limit, true) as $entry) {
            $results[] = new SearchResult(
                $entry['subscriber']->id,
                $entry['subscriber']->account_no,
                $entry['subscriber']->full_name,
                $entry['subscriber']->status,
                $entry['labels'],
            );
        }

        return $results;
    }
}
