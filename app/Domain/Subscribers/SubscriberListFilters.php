<?php

declare(strict_types=1);

namespace App\Domain\Subscribers;

use App\Domain\Subscribers\Enums\SubscriberStatus;

/** What the All Subscribers screen can filter by (FR-SUB-003). */
final readonly class SubscriberListFilters
{
    public function __construct(
        public string $search = '',
        public ?SubscriberStatus $status = null,
        public ?int $areaId = null,
        public ?int $collectorId = null,
        public ?int $serviceTypeId = null,
    ) {}
}
