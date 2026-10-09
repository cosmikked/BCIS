<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Enums\ServiceStatus;

/** What the Service Accounts screen can filter by. */
final readonly class ServiceAccountFilters
{
    public function __construct(
        public string $search = '',
        public ?ServiceStatus $status = null,
        public ?int $serviceTypeId = null,
        public ?int $collectorId = null,
        public ?int $areaId = null,
        public ?int $subscriberId = null,
    ) {}
}
