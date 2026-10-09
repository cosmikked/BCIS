<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-SUB-002, FR-SUB-007, FR-SUB-008, FR-SUB-011, INV-01, INV-10, INV-19, INV-20, INV-21.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_no', 32)->unique();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('service_plans')->restrictOnDelete();
            $table->foreignId('service_address_id')->constrained('subscriber_addresses')->restrictOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->date('activation_date')->nullable();
            $table->date('billing_start_date');
            $table->unsignedSmallInteger('billing_due_day');
            // INV-20: copied from the plan when the account is created. Editing
            // the plan later never touches this column.
            $table->bigInteger('current_rate_centavos');
            // Current collector only. The dated history is collector_assignments.
            $table->foreignId('assigned_collector_id')->nullable()->constrained('collectors')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('subscriber_id');
            $table->index('plan_id');
            $table->index('service_address_id');
            $table->index(['assigned_collector_id', 'status']);
            $table->index('status');
        });

        // OWNED BY ties the sequence to the table so migrate:fresh recreates it.
        DB::statement('CREATE SEQUENCE service_account_no_seq OWNED BY service_accounts.account_no');

        DB::statement("ALTER TABLE service_accounts ADD CONSTRAINT service_accounts_status_check CHECK (status IN ('PENDING', 'ACTIVE', 'SUSPENDED', 'TERMINATED'))");
        DB::statement('ALTER TABLE service_accounts ADD CONSTRAINT service_accounts_due_day_check CHECK (billing_due_day BETWEEN 1 AND 31)');
        DB::statement('ALTER TABLE service_accounts ADD CONSTRAINT service_accounts_rate_check CHECK (current_rate_centavos > 0)');

        Schema::create('service_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_account_id')->constrained('service_accounts')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            // Stamped by the database clock (INV-22).
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            // The reason entered for the change (FR-SUB-008).
            $table->text('notes')->nullable();

            $table->index(['service_account_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE service_events ADD CONSTRAINT service_events_type_check CHECK (event_type IN ('CREATED', 'ACTIVATED', 'SUSPENDED', 'RECONNECTED', 'TERMINATED', 'PLAN_CHANGED', 'COLLECTOR_CHANGED'))");

        // INV-21: history is never edited or deleted.
        AppendOnly::guard('service_events');

        Schema::create('collector_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collector_id')->constrained('collectors')->restrictOnDelete();
            $table->foreignId('collection_area_id')->nullable()->constrained('collection_areas')->restrictOnDelete();
            $table->foreignId('service_account_id')->nullable()->constrained('service_accounts')->restrictOnDelete();
            $table->date('effective_from');
            // Null means the assignment is still open.
            $table->date('effective_to')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['collector_id', 'effective_from']);
            $table->index('service_account_id');
            $table->index('collection_area_id');
        });

        // FR-SUB-011: an assignment targets an area or one service account, never both.
        DB::statement('ALTER TABLE collector_assignments ADD CONSTRAINT collector_assignments_one_target_check CHECK (num_nonnulls(collection_area_id, service_account_id) = 1)');
        DB::statement('ALTER TABLE collector_assignments ADD CONSTRAINT collector_assignments_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        // At most one open assignment per target, enforced by the database.
        DB::statement('CREATE UNIQUE INDEX collector_assignments_open_account ON collector_assignments (service_account_id) WHERE effective_to IS NULL AND service_account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX collector_assignments_open_area ON collector_assignments (collection_area_id) WHERE effective_to IS NULL AND collection_area_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_assignments');
        // The bcis_reject_modification() function is shared with audit_logs, so
        // it is left in place here.
        Schema::dropIfExists('service_events');
        Schema::dropIfExists('service_accounts');
    }
};
