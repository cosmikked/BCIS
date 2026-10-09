<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Search;

use App\Domain\Subscribers\Enums\SubscriberStatus;

/** One subscriber found by the global search, with what it matched on. */
final readonly class SearchResult
{
    /** @param  list<string>  $matchedOn  source labels such as "Name" or "Contact" */
    public function __construct(
        public int $subscriberId,
        public string $accountNo,
        public string $fullName,
        public SubscriberStatus $status,
        public array $matchedOn,
    ) {}
}
