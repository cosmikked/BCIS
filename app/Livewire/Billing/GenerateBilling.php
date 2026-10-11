<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domain\Billing\Actions\DiscardDraftInvoices;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Actions\PreviewBilling;
use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\Enums\BillingCycleStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\SkipReason;
use App\Domain\Billing\SkippedAccount;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Generate Billing (FR-BIL-001, FR-BIL-003, FR-BIL-004, FR-BIL-005): pick a
 * month, preview who will be billed and who is skipped, generate the drafts,
 * review them, then discard or finalize. Thin: the four Actions hold every rule.
 *
 * The preview is recomputed on each render while it is open and never saved. The
 * cycle id is always looked up from the chosen month on the server, so nothing
 * the browser sends can point an action at another cycle.
 */
#[Title('Generate Billing')]
class GenerateBilling extends Component
{
    use AuthorizesRequests;

    private const SHOWN = 100;

    /** Billing month as Y-m. */
    #[Url]
    public string $period = '';

    #[Locked]
    public bool $previewing = false;

    #[Locked]
    public string $message = '';

    public string $discardReason = '';

    public function boot(): void
    {
        $this->authorize('billing.generate');
    }

    public function mount(): void
    {
        $this->authorize('billing.generate');

        if ($this->period === '') {
            $this->period = CarbonImmutable::today()->format('Y-m');
        }
    }

    public function updatedPeriod(): void
    {
        $this->authorize('billing.generate');

        $this->previewing = false;
        $this->message = '';
        $this->resetErrorBag();
    }

    public function preview(PreviewBilling $previewBilling): void
    {
        $this->authorize('billing.generate');

        // Validates the month (and that it has started) before the preview is shown.
        $previewBilling->handle($this->actor(), $this->period);

        $this->previewing = true;
        $this->message = '';
        $this->resetErrorBag();
    }

    public function generate(GenerateBillingCycle $generate): void
    {
        $this->authorize('billing.generate');

        $result = $generate->handle($this->actor(), $this->period);

        $this->previewing = false;
        $this->message = $result->created === 0
            ? "Nothing new to bill for {$result->cycle->period_month->format('F Y')}: {$result->alreadyBilled} already billed, {$result->ineligible} skipped."
            : "{$result->created} draft invoices created for {$result->cycle->period_month->format('F Y')}. Review them, then finalize.";
        $this->resetErrorBag();
    }

    public function discard(DiscardDraftInvoices $discard): void
    {
        $this->authorize('billing.generate');

        $cycle = $this->cycleForPeriod();
        abort_if($cycle === null, 422, 'There is no billing cycle for this month.');

        $discard->handle($this->actor(), $cycle->id, $this->discardReason);

        $this->message = 'Draft invoices discarded. Nothing was numbered or posted. You can generate the month again.';
        $this->reset('discardReason');
        $this->resetErrorBag();
    }

    public function finalize(FinalizeBillingCycle $finalize): void
    {
        $this->authorize('billing.generate');

        $cycle = $this->cycleForPeriod();
        abort_if($cycle === null, 422, 'There is no billing cycle for this month.');

        $done = $finalize->handle($this->actor(), $cycle->id);

        $this->message = "{$done->period_month->format('F Y')} finalized: {$done->invoice_count} invoices numbered and posted to the ledger.";
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('billing.generate');

        $period = $this->validPeriod();
        $cycle = $period !== null ? BillingCycle::query()->where('period_month', $period->toDateString())->first() : null;

        $preview = null;
        if ($this->previewing && $period !== null) {
            try {
                $preview = app(PreviewBilling::class)->handle($this->actor(), $period);
            } catch (ValidationException) {
                $this->previewing = false;
            }
        }

        $drafts = $cycle?->status === BillingCycleStatus::Draft
            ? Invoice::query()->with(['subscriber', 'serviceAccount'])
                ->where('billing_cycle_id', $cycle->id)
                ->where('status', InvoiceStatus::Draft->value)
                ->orderBy('id')->limit(self::SHOWN)->get()
            : collect();

        return view('livewire.billing.generate-billing', [
            'periodDate' => $period,
            'cycle' => $cycle,
            'preview' => $preview,
            'lines' => $preview !== null ? array_slice($preview->plan->lines, 0, self::SHOWN) : [],
            'skippedGroups' => $preview !== null ? $this->groupSkipped($preview->plan->skipped) : [],
            'drafts' => $drafts,
            'draftCount' => $cycle !== null && $cycle->status === BillingCycleStatus::Draft ? $cycle->invoice_count : 0,
            'shown' => self::SHOWN,
        ]);
    }

    /**
     * @param  list<SkippedAccount>  $skipped
     * @return list<array{reason: SkipReason, count: int, names: list<string>}>
     */
    private function groupSkipped(array $skipped): array
    {
        $groups = [];
        foreach ($skipped as $item) {
            $key = $item->reason->value;
            $groups[$key] ??= ['reason' => $item->reason, 'count' => 0, 'names' => []];
            $groups[$key]['count']++;
            if (count($groups[$key]['names']) < 10) {
                $groups[$key]['names'][] = $item->account->account_no;
            }
        }

        return array_values($groups);
    }

    private function validPeriod(): ?CarbonImmutable
    {
        try {
            return BillingPeriod::billable($this->period);
        } catch (ValidationException) {
            return null;
        }
    }

    private function cycleForPeriod(): ?BillingCycle
    {
        $period = BillingPeriod::firstOfMonth($this->period);

        return BillingCycle::query()->where('period_month', $period->toDateString())->first();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
