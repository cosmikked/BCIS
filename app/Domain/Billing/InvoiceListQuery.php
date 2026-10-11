<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Security\SettingsRepository;
use App\Domain\Subscribers\ContactNormalizer;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The query behind the Invoices list and the subscriber's Billing tab
 * (FR-BIL-014, NFR-02). Draft invoices have no number and are not part of the
 * ledger, so the list shows them only when asked for by cycle or status.
 *
 * "Overdue" is derived here exactly as InvoiceStatusResolver derives it: a
 * collectible invoice whose due date plus the grace days is before today. No
 * stored column is involved, so changing billing.grace_days changes the list.
 */
final class InvoiceListQuery
{
    public function __construct(
        private readonly GlobalSearch $search,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return Builder<Invoice> */
    public function build(User $actor, InvoiceFilters $filters): Builder
    {
        Gate::forUser($actor)->authorize('billing.view');

        $query = Invoice::query()->with(['subscriber', 'serviceAccount']);

        $term = GlobalSearch::clean($filters->search);

        if (preg_match('/^[a-z]/i', $term) === 1 && InvoiceNumberSource::digitsOf($term) !== null) {
            // A typed invoice number ("INV-000123") finds that invoice, not every invoice of its
            // owner. Bare digits still search phone numbers and the like as well.
            $query->where('invoices.invoice_no', 'ilike', '%'.InvoiceNumberSource::digitsOf($term).'%');
        } elseif (GlobalSearch::isSearchable($term)) {
            $subscribers = Subscriber::query()->select('subscribers.id');
            $this->search->applyTo($subscribers, $term);

            $like = '%'.ContactNormalizer::escapeLike($term).'%';
            $query->where(function (Builder $inner) use ($like, $subscribers): void {
                $inner->where('invoices.invoice_no', 'ilike', $like)
                    ->orWhereIn('invoices.subscriber_id', $subscribers->toBase());
            });
        }

        if ($filters->period !== null) {
            $query->where('invoices.billing_period', $filters->period);
        }

        if ($filters->status !== null) {
            $query->where('invoices.status', $filters->status->value);
        } elseif ($filters->billingCycleId === null) {
            $query->where('invoices.status', '<>', InvoiceStatus::Draft->value);
        }

        if ($filters->overdueOnly) {
            $cutoff = CarbonImmutable::today()->subDays($this->settings->int('billing.grace_days'));
            $query->whereIn('invoices.status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
                ->where('invoices.due_date', '<', $cutoff->toDateString());
        }

        if ($filters->type !== null) {
            $query->where('invoices.invoice_type', $filters->type->value);
        }

        if ($filters->subscriberId !== null) {
            $query->where('invoices.subscriber_id', $filters->subscriberId);
        }

        if ($filters->billingCycleId !== null) {
            $query->where('invoices.billing_cycle_id', $filters->billingCycleId);
        }

        return $query->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id');
    }
}
