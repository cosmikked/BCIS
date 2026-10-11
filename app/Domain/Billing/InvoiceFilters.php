<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;

/** The filters of the Invoices list. OVERDUE is not a stored status, so it is a flag, not an InvoiceStatus. */
final readonly class InvoiceFilters
{
    public function __construct(
        public string $search = '',
        /** First day of a billing month, or null for every month. */
        public ?string $period = null,
        public ?InvoiceStatus $status = null,
        public bool $overdueOnly = false,
        public ?InvoiceType $type = null,
        public ?int $subscriberId = null,
        public ?int $billingCycleId = null,
    ) {}
}
