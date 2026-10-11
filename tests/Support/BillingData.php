<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raw-insert builders for billing tables. They skip the Actions on purpose, so
 * database rules and calculators can be tested on rows in any state, including
 * ones the application would never create.
 */
final class BillingData
{
    /**
     * A billable account made through the real Actions: created PENDING, then
     * activated. Billing start defaults to 2026-10-01 (see SubscriberData).
     *
     * @param  array<string, mixed>  $overrides  extra input for CreateServiceAccount
     */
    public static function activeAccount(User $actor, array $overrides = [], ?ServicePlan $plan = null): ServiceAccount
    {
        $account = SubscriberData::account($actor, null, $plan, $overrides);

        return app(ChangeServiceStatus::class)->handle($actor, $account->id, ServiceStatus::Active, 'Installed');
    }

    /** @param  array<string, mixed>  $overrides */
    public static function invoice(ServiceAccount $account, array $overrides = []): int
    {
        $draft = ($overrides['status'] ?? 'DRAFT') === 'DRAFT';
        $type = $overrides['invoice_type'] ?? 'MONTHLY';
        $period = $overrides['billing_period'] ?? '2026-10-01';
        $cycleId = $overrides['billing_cycle_id'] ?? ($type === 'MONTHLY' ? self::cycle($period) : null);

        return (int) DB::table('invoices')->insertGetId([...[
            'invoice_no' => $draft ? null : 'INV-'.str_pad((string) DB::selectOne("SELECT nextval('invoice_no_seq') AS n")->n, 6, '0', STR_PAD_LEFT),
            'billing_cycle_id' => $cycleId,
            'service_account_id' => $account->id,
            'subscriber_id' => $account->subscriber_id,
            'invoice_type' => $type,
            'billing_period' => $type === 'MONTHLY' ? $period : null,
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-05',
            'total_centavos' => 99900,
            'status' => 'DRAFT',
            'finalized_at' => $draft ? null : now(),
        ], ...$overrides]);
    }

    /**
     * A finalized UNPAID invoice and its ledger debit, written raw so a test can
     * have as many as it needs without running the monthly generator. It is ONE_OFF
     * so the monthly uniqueness rule never applies, and it has no items (the
     * adjustment rules do not read them).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function postedInvoice(ServiceAccount $account, int $userId, int $total = 99900, array $overrides = []): Invoice
    {
        $id = self::invoice($account, [...[
            'status' => 'UNPAID',
            'total_centavos' => $total,
            'invoice_type' => 'ONE_OFF',
        ], ...$overrides]);

        self::ledger($account, $userId, [
            'debit_centavos' => $total,
            'reference_type' => 'invoice',
            'reference_id' => $id,
        ]);

        return Invoice::query()->findOrFail($id);
    }

    public static function cycle(string $period): int
    {
        $existing = DB::table('billing_cycles')->where('period_month', $period)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('billing_cycles')->insertGetId([
            'period_month' => $period,
            'generated_by' => User::query()->orderBy('id')->value('id'),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function item(int $invoiceId, array $overrides = []): int
    {
        return (int) DB::table('invoice_items')->insertGetId([...[
            'invoice_id' => $invoiceId,
            'item_type' => 'SUBSCRIPTION',
            'description' => 'Monthly subscription',
            'amount_centavos' => 99900,
        ], ...$overrides]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function ledger(ServiceAccount $account, int $userId, array $overrides = []): int
    {
        return (int) DB::table('ledger_entries')->insertGetId([...[
            'subscriber_id' => $account->subscriber_id,
            'service_account_id' => $account->id,
            'entry_date' => '2026-10-01',
            'entry_type' => 'INVOICE',
            'debit_centavos' => 99900,
            'credit_centavos' => 0,
            'description' => 'Test entry',
            'created_by' => $userId,
        ], ...$overrides]);
    }

    /** A PENDING request unless $overrides decide it. */
    public static function adjustment(ServiceAccount $account, int $invoiceId, int $requestedBy, array $overrides = []): int
    {
        return (int) DB::table('adjustments')->insertGetId([...[
            'adjustment_no' => 'ADJ-'.str_pad((string) DB::selectOne("SELECT nextval('adjustment_no_seq') AS n")->n, 6, '0', STR_PAD_LEFT),
            'subscriber_id' => $account->subscriber_id,
            'service_account_id' => $account->id,
            'invoice_id' => $invoiceId,
            'kind' => 'CREDIT',
            'amount_centavos' => 10000,
            'reason' => 'Promo not applied',
            'requested_by' => $requestedBy,
        ], ...$overrides]);
    }

    /** An APPROVED adjustment, decided by $approvedBy. */
    public static function approvedAdjustment(ServiceAccount $account, int $invoiceId, int $requestedBy, int $approvedBy, string $kind, int $amount): int
    {
        return self::adjustment($account, $invoiceId, $requestedBy, [
            'kind' => $kind, 'amount_centavos' => $amount, 'status' => 'APPROVED',
            'approved_by' => $approvedBy, 'decided_at' => now(),
        ]);
    }
}
