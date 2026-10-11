<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Actions\ApproveAdjustment;
use App\Domain\Billing\Actions\CreateAdjustment;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Enums\AdjustmentKind;
use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Models\BillingCycle;
use App\Models\Invoice;
use App\Models\ServiceAccount;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Three billed months of synthetic history: August, September and October 2026
 * (PRD section 18, F2). Everything goes through the real Actions as the Owner, so
 * the invoices, ledger entries and audit rows are exactly what live use makes.
 * Skipped in production and when a billing cycle already exists.
 *
 * The demo subscribers were installed "this month", so a few set-up steps come
 * first (and are the only direct writes in this file):
 *  - accounts that start on 2026-10-01 are moved back to 2026-08-01 so they have
 *    three months of invoices. Ana Reyes keeps 2026-10-01 and is activated just
 *    before October, so her first invoice is October: Combo 1,499.00 plus the
 *    500.00 installation fee = 1,999.00, due Oct 5. Elena and Elaine Cruz start
 *    2026-09-01 and get two invoices. Pending and terminated accounts are not billed.
 *  - Carlo Mendoza and Dina Flores are suspended in the subscriber seed. They are
 *    reconnected for the three months and suspended again at the end, so each ends
 *    with three unpaid invoices and a SUSPENDED account, as in the story.
 *
 * Two corrections show the maker-checker flow, both requested by the Administrator
 * and approved by the Owner, both on the September invoices of two unnamed
 * subscribers (so the named people keep the amounts PRD section 18 relies on): a
 * 100.00 service credit, and the void of an invoice billed in error.
 *
 * A month that has not started yet (when the demo database is built early) is
 * simply not billed.
 */
class DemoBillingSeeder extends Seeder
{
    private const MONTHS = ['2026-08', '2026-09', '2026-10'];

    /** The people PRD section 18 tells stories about; their invoices stay untouched by the demo corrections. */
    private const NAMED_PEOPLE = ['Ana Reyes', 'Ben Santos', 'Carlo Mendoza', 'Dina Flores', 'Elena Cruz', 'Elaine Cruz', 'Gil Ramos'];

    public function run(): void
    {
        if (app()->environment('production') || BillingCycle::query()->exists() || ! Subscriber::query()->exists()) {
            return;
        }

        $owner = $this->userWithRole(RoleCode::Owner);
        $admin = $this->userWithRole(RoleCode::Administrator);
        $changeStatus = app(ChangeServiceStatus::class);

        $this->backdateBillingStart();

        $carlo = $this->accountOf('Carlo Mendoza');
        $dina = $this->accountOf('Dina Flores');
        $ana = $this->accountOf('Ana Reyes');

        foreach ([$carlo, $dina] as $account) {
            $changeStatus->handle($owner, $account->id, ServiceStatus::Active, 'Demo: reconnected for the billing history');
        }

        $thisMonth = CarbonImmutable::today()->startOfMonth();

        foreach (self::MONTHS as $month) {
            if (CarbonImmutable::parse($month.'-01')->greaterThan($thisMonth)) {
                break;
            }

            if ($month === '2026-10') {
                $changeStatus->handle($owner, $ana->id, ServiceStatus::Active, 'Demo: installation complete');
            }

            $cycle = app(GenerateBillingCycle::class)->handle($owner, $month)->cycle;
            app(FinalizeBillingCycle::class)->handle($owner, $cycle->id);

            if ($month === '2026-09') {
                $this->demoCorrections($owner, $admin);
            }
        }

        foreach ([$carlo, $dina] as $account) {
            $changeStatus->handle($owner, $account->id, ServiceStatus::Suspended, 'Demo: unpaid invoices');
        }

        $this->assertTiesOut();
    }

    /** Accounts that started on 2026-10-01 were installed in August in the demo story. */
    private function backdateBillingStart(): void
    {
        $anaId = $this->accountOf('Ana Reyes')->id;

        DB::table('service_accounts')
            ->where('billing_start_date', '2026-10-01')
            ->where('status', ServiceStatus::Active->value)
            ->where('id', '<>', $anaId)
            ->update(['billing_start_date' => '2026-08-01', 'activation_date' => '2026-08-01']);
    }

    private function demoCorrections(User $owner, User $admin): void
    {
        // Two unnamed subscribers, so the named people keep the exact amounts the PRD
        // scenarios (and Phase 5's payments) are written against.
        [$creditAccount, $voidAccount] = $this->unnamedBilledAccounts();

        $invoice = Invoice::query()->where('service_account_id', $creditAccount->id)->where('billing_period', '2026-09-01')->firstOrFail();
        $credit = app(CreateAdjustment::class)->handle($admin, $invoice->id, AdjustmentKind::Credit, '100.00', 'Demo: service interruption credit');
        app(ApproveAdjustment::class)->handle($owner, $credit->id, 'Demo: approved');

        $invoice = Invoice::query()->where('service_account_id', $voidAccount->id)->where('billing_period', '2026-09-01')->firstOrFail();
        $void = app(CreateAdjustment::class)->handle($admin, $invoice->id, AdjustmentKind::Void, null, 'Demo: billed in error');
        app(ApproveAdjustment::class)->handle($owner, $void->id, 'Demo: confirmed with the subscriber');
    }

    /**
     * The first two active accounts billed since August whose subscriber is not one of the
     * named demo people.
     *
     * @return array{0: ServiceAccount, 1: ServiceAccount}
     */
    private function unnamedBilledAccounts(): array
    {
        $accounts = ServiceAccount::query()
            ->where('status', ServiceStatus::Active->value)
            ->where('billing_start_date', '2026-08-01')
            ->whereHas('subscriber', fn ($q) => $q->whereNotIn('full_name', self::NAMED_PEOPLE))
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($accounts->count() < 2) {
            throw new RuntimeException('The demo billing needs two unnamed active subscribers (run DemoSubscriberSeeder).');
        }

        return [$accounts[0], $accounts[1]];
    }

    /**
     * The seeded history must foot before anyone sees it (TO-02): finalized
     * invoices equal the invoice debits on the ledger, to the centavo.
     */
    private function assertTiesOut(): void
    {
        $invoiced = (int) Invoice::query()->whereNotNull('invoice_no')->sum('total_centavos');
        $debited = (int) DB::table('ledger_entries')->where('entry_type', 'INVOICE')->sum('debit_centavos');

        if ($invoiced !== $debited) {
            throw new RuntimeException("Demo billing does not tie out: invoices {$invoiced} centavos, ledger debits {$debited}.");
        }
    }

    private function accountOf(string $subscriberName): ServiceAccount
    {
        return ServiceAccount::query()
            ->whereHas('subscriber', fn ($q) => $q->where('full_name', $subscriberName))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function userWithRole(RoleCode $role): User
    {
        $user = User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('code', $role->value))
            ->orderBy('id')
            ->first();

        if ($user === null) {
            throw new RuntimeException("Seed the {$role->value} user before the demo billing (OwnerSeeder, DemoUserSeeder).");
        }

        return $user;
    }
}
