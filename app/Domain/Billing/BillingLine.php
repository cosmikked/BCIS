<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Money\Money;
use App\Models\ServiceAccount;
use Carbon\CarbonImmutable;

/**
 * One invoice the generator is about to create for an account: the preview shows
 * it, GenerateBillingCycle writes it. total() is the sum of the items, so the
 * invoice total always equals its items (FR-BIL-006).
 */
final readonly class BillingLine
{
    public function __construct(
        public ServiceAccount $account,
        public string $subscriptionDescription,
        /** The account's rate, copied from it and not looked up on the plan (INV-20). */
        public Money $rate,
        /** Zero unless this is the account's first invoice and it carries a fee (PRD 19 #6). */
        public Money $installationFee,
        public CarbonImmutable $invoiceDate,
        public CarbonImmutable $dueDate,
    ) {}

    public function total(): Money
    {
        return $this->rate->add($this->installationFee);
    }

    public function hasInstallationFee(): bool
    {
        return $this->installationFee->isPositive();
    }
}
