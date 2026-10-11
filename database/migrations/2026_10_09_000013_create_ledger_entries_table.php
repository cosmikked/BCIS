<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-BIL-014, FR-BIL-016, INV-01, INV-02, INV-03, INV-19.
//
// The subscriber ledger. Debits come from invoices and debit adjustments,
// credits from payments and credit adjustments. A correction is a NEW row;
// no row is ever updated or deleted. The running balance is computed from the
// entries (window function), it is never stored as the authority.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->foreignId('service_account_id')->nullable()->constrained('service_accounts')->restrictOnDelete();
            // Business date (Asia/Manila). posted_at is the server clock.
            $table->date('entry_date');
            $table->timestampTz('posted_at')->useCurrent();
            $table->string('entry_type', 20);
            $table->bigInteger('debit_centavos')->default(0);
            $table->bigInteger('credit_centavos')->default(0);
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no', 32)->nullable();
            $table->string('description', 255);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->index(['subscriber_id', 'entry_date', 'id']);
            $table->index('service_account_id');
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (entry_type IN ('INVOICE', 'PAYMENT', 'PAYMENT_REVERSAL', 'ADJUSTMENT'))");
        // Exactly one side carries the amount, and it is positive (INV-02).
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_one_side_check CHECK (
            (debit_centavos > 0 AND credit_centavos = 0) OR (credit_centavos > 0 AND debit_centavos = 0)
        )');

        // One ledger row per invoice and one per adjustment, even if code ran twice.
        DB::statement("CREATE UNIQUE INDEX ledger_entries_invoice_once ON ledger_entries (reference_id) WHERE entry_type = 'INVOICE'");
        DB::statement("CREATE UNIQUE INDEX ledger_entries_adjustment_once ON ledger_entries (reference_id) WHERE entry_type = 'ADJUSTMENT'");

        AppendOnly::guard('ledger_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
