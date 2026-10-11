<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\CreateOneOffInvoice;
use App\Domain\Billing\Actions\FinalizeBillingCycle;
use App\Domain\Billing\Actions\GenerateBillingCycle;
use App\Domain\Billing\Contracts\CustomerCreditApplier;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Money\Money;
use App\Domain\Security\AuditAction;
use App\Domain\Security\RoleCode;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\ServicePlan;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BillingData;
use Tests\Support\Users;

// FR-BIL-010, FR-BIL-013, INV-05, INV-09, INV-10, INV-11

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->owner = Users::make('owner', RoleCode::Owner);
    $this->cashier = Users::make('cashier', RoleCode::Cashier);

    $plan = ServicePlan::factory()->create(['price_centavos' => Money::fromCentavos(99900)]);
    $this->account = BillingData::activeAccount($this->owner, plan: $plan);
});

function reconnectionFee(string $amount = '500.00'): array
{
    return [['type' => InvoiceItemType::Reconnection, 'description' => 'Reconnection fee', 'amount' => $amount]];
}

describe('one-off invoice (FR-BIL-010)', function (): void {
    it('is numbered, UNPAID and debited to the ledger in one step', function (): void {
        $invoice = app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, reconnectionFee(), 'Reconnected after payment');

        expect($invoice->invoice_no)->toMatch('/^INV-\d{6}$/')
            ->and($invoice->invoice_type)->toBe(InvoiceType::OneOff)
            ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
            ->and($invoice->billing_cycle_id)->toBeNull()
            ->and($invoice->billing_period)->toBeNull()
            ->and($invoice->total_centavos->centavos)->toBe(50000)
            ->and($invoice->invoice_date->toDateString())->toBe('2026-10-09')
            ->and($invoice->due_date->toDateString())->toBe('2026-10-09')
            ->and($invoice->finalized_at)->not->toBeNull()
            ->and($invoice->subscriber_id)->toBe($this->account->subscriber_id);

        $items = $invoice->items()->get();
        expect($items)->toHaveCount(1)
            ->and($items[0]->item_type)->toBe(InvoiceItemType::Reconnection)
            ->and($items[0]->amount_centavos->centavos)->toBe(50000);

        $entry = LedgerEntry::query()->sole();
        expect($entry->debit_centavos->centavos)->toBe(50000)
            ->and($entry->reference_id)->toBe($invoice->id)
            ->and($entry->reference_no)->toBe($invoice->invoice_no);

        $log = AuditLog::query()->where('action', AuditAction::OneOffInvoiceCreated->value)->sole();
        expect($log->reason)->toBe('Reconnected after payment')
            ->and($log->new_values['total_centavos'])->toBe(50000);
    });

    it('sums several items and takes a chosen due date', function (): void {
        $invoice = app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, [
            ['type' => InvoiceItemType::Reconnection, 'description' => 'Reconnection fee', 'amount' => '500.00'],
            ['type' => InvoiceItemType::Other, 'description' => 'Replacement modem', 'amount' => '1,250.50'],
        ], 'Visit', '2026-10-20');

        expect($invoice->total_centavos->centavos)->toBe(175050)
            ->and($invoice->due_date->toDateString())->toBe('2026-10-20')
            ->and(LedgerEntry::query()->sole()->debit_centavos->centavos)->toBe(175050);
    });

    it('can sit next to the same month\'s regular invoice (INV-09 does not apply)', function (): void {
        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
        app(FinalizeBillingCycle::class)->handle($this->owner, $cycle->id);

        app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, reconnectionFee(), 'Reconnected');
        app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, reconnectionFee(), 'Reconnected again');

        $invoices = Invoice::query()->where('service_account_id', $this->account->id)->get();
        expect($invoices)->toHaveCount(3)
            ->and($invoices->where('invoice_type', InvoiceType::Monthly)->count())->toBe(1)
            ->and($invoices->pluck('invoice_no')->unique())->toHaveCount(3);

        // Tie-out: the ledger debits equal the finalized invoices (TO-02).
        expect((int) LedgerEntry::query()->sum('debit_centavos'))->toBe((int) Invoice::query()->sum('total_centavos'));
    });

    it('stops the first monthly invoice charging an installation fee already billed here', function (): void {
        $account = BillingData::activeAccount($this->owner, ['installation_fee' => '500.00']);

        app(CreateOneOffInvoice::class)->handle($this->owner, $account->id, [
            ['type' => InvoiceItemType::Installation, 'description' => 'Installation fee', 'amount' => '500.00'],
        ], 'Installed separately');

        expect($account->fresh()->installation_fee_billed_at)->not->toBeNull();

        $cycle = app(GenerateBillingCycle::class)->handle($this->owner, '2026-10')->cycle;
        $monthly = Invoice::query()->where('billing_cycle_id', $cycle->id)->where('service_account_id', $account->id)->sole();

        expect($monthly->items()->where('item_type', 'INSTALLATION')->count())->toBe(0);
    });

    it('validates the items', function (array $items): void {
        expect(fn () => app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, $items, 'Because'))
            ->toThrow(ValidationException::class)
            ->and(Invoice::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    })->with([
        'none' => [[]],
        'zero amount' => [[['type' => InvoiceItemType::Other, 'description' => 'Fee', 'amount' => '0.00']]],
        'negative amount' => [[['type' => InvoiceItemType::Other, 'description' => 'Fee', 'amount' => '-5.00']]],
        'bad amount' => [[['type' => InvoiceItemType::Other, 'description' => 'Fee', 'amount' => 'abc']]],
        'blank description' => [[['type' => InvoiceItemType::Other, 'description' => '  ', 'amount' => '5.00']]],
        'a discount' => [[['type' => InvoiceItemType::Discount, 'description' => 'Promo', 'amount' => '5.00']]],
        'a penalty' => [[['type' => InvoiceItemType::Penalty, 'description' => 'Late', 'amount' => '5.00']]],
        'a subscription' => [[['type' => InvoiceItemType::Subscription, 'description' => 'Plan', 'amount' => '5.00']]],
    ]);

    it('needs a reason, a sensible due date and the billing permission', function (): void {
        $make = fn ($user, string $reason = 'Because', ?string $due = null) => app(CreateOneOffInvoice::class)
            ->handle($user, $this->account->id, reconnectionFee(), $reason, $due);

        expect(fn () => $make($this->owner, ' '))->toThrow(ValidationException::class)
            ->and(fn () => $make($this->owner, 'Because', '2026-10-08'))->toThrow(ValidationException::class)
            ->and(fn () => $make($this->owner, 'Because', '2026-02-30'))->toThrow(ValidationException::class)
            ->and(fn () => $make($this->owner, 'Because', 'soon'))->toThrow(ValidationException::class)
            ->and(fn () => $make($this->cashier))->toThrow(AuthorizationException::class)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('leaves no invoice, number or ledger row when the credit hook fails (INV-11)', function (): void {
        $this->app->bind(CustomerCreditApplier::class, fn () => new class implements CustomerCreditApplier
        {
            public function apply(Invoice $invoice, User $actor): void
            {
                throw new RuntimeException('credit failed');
            }
        });

        expect(fn () => app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, reconnectionFee(), 'Because'))
            ->toThrow(RuntimeException::class);

        expect(Invoice::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', AuditAction::OneOffInvoiceCreated->value)->count())->toBe(0)
            ->and($this->account->fresh()->installation_fee_billed_at)->toBeNull();
    });

    it('is immutable once created', function (): void {
        $invoice = app(CreateOneOffInvoice::class)->handle($this->owner, $this->account->id, reconnectionFee(), 'Because');

        expect(fn () => $invoice->forceFill(['total_centavos' => Money::fromCentavos(1)])->save())->toThrow(LogicException::class)
            ->and(fn () => $invoice->delete())->toThrow(LogicException::class);
    });
});
