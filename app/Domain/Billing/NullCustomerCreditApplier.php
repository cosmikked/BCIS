<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Models\Invoice;
use App\Models\User;

/** Phase 4 stand-in: there is no customer credit until payments exist (Phase 5). */
final class NullCustomerCreditApplier implements CustomerCreditApplier
{
    public function apply(Invoice $invoice, User $actor): void {}
}
