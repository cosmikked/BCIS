<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Enums\ServiceStatus;
use Illuminate\Support\Carbon;

/**
 * One row of the Technician's job list (FR-SUB-013, PRD section 2): what is
 * needed to do the work and nothing else. This is the ONLY shape the screen
 * receives, so a financial field cannot reach the response by accident: there
 * is no rate, due day, billing date, balance, invoice or payment here, and
 * OperationalServiceQuery does not select any of them from the database.
 * A test checks both.
 */
final readonly class OperationalServiceRow
{
    public function __construct(
        public int $id,
        public string $accountNo,
        public string $subscriberName,
        public string $serviceAddress,
        public string $planName,
        public string $serviceType,
        public ServiceStatus $status,
        public ?Carbon $statusDate,
    ) {}

    /** What the technician is expected to do for this account. */
    public function instruction(): string
    {
        return match ($this->status) {
            ServiceStatus::Pending => 'Install the service',
            ServiceStatus::Active => 'In service',
            ServiceStatus::Suspended => 'Disconnected. Keep it disconnected until a reconnection is requested',
            ServiceStatus::Terminated => 'Service ended',
        };
    }
}
