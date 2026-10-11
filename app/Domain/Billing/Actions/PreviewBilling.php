<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\BillingPlanner;
use App\Domain\Billing\BillingPreview;
use App\Models\BillingCycle;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * FR-BIL-001. Shows the accounts to bill, the accounts skipped with the reason
 * and the total. It saves nothing: only Generate creates invoices.
 */
final class PreviewBilling
{
    public function __construct(private readonly BillingPlanner $planner) {}

    /** @throws ValidationException */
    public function handle(User $actor, CarbonInterface|string $period): BillingPreview
    {
        Gate::forUser($actor)->authorize('billing.generate');

        $period = BillingPeriod::billable($period);

        return new BillingPreview(
            $this->planner->plan($period),
            BillingCycle::query()->where('period_month', $period->toDateString())->first(),
        );
    }
}
