<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Models\Subscriber;

/** A probable duplicate and why it was flagged (FR-SUB-012). */
final readonly class DuplicateMatch
{
    public function __construct(
        public Subscriber $subscriber,
        public string $reason,
    ) {}
}
