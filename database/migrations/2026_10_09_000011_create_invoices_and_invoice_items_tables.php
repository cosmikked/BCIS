<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-BIL-003, FR-BIL-005, FR-BIL-006, FR-BIL-008, FR-BIL-009, FR-BIL-010, FR-BIL-013,
// INV-01, INV-05, INV-09, INV-10, INV-19, INV-20.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            // Null while DRAFT. Assigned from invoice_no_seq at finalization (INV-10).
            $table->string('invoice_no', 32)->nullable()->unique();
            // Null for ONE_OFF invoices, which are not part of a monthly cycle.
            $table->foreignId('billing_cycle_id')->nullable()->constrained('billing_cycles')->restrictOnDelete();
            $table->foreignId('service_account_id')->constrained('service_accounts')->restrictOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->string('invoice_type', 20);
            // First day of the billed month. Null for ONE_OFF.
            $table->date('billing_period')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->bigInteger('total_centavos');
            // OVERDUE is never stored: it is derived from due date, grace period
            // and balance (FR-BIL-008).
            $table->string('status', 20)->default('DRAFT');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['due_date', 'status']);
            $table->index(['subscriber_id', 'status']);
            $table->index('billing_cycle_id');
            $table->index('service_account_id');
        });

        // INV-10: numbers come from a PostgreSQL sequence, never max + 1.
        // OWNED BY ties the sequence to the table so migrate:fresh recreates it.
        DB::statement('CREATE SEQUENCE invoice_no_seq OWNED BY invoices.invoice_no');

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (invoice_type IN ('MONTHLY', 'ONE_OFF'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('DRAFT', 'UNPAID', 'PARTIALLY_PAID', 'PAID', 'VOID', 'CREDITED'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_check CHECK (total_centavos >= 0)');
        // A number exists exactly when the invoice is no longer a draft.
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_number_check CHECK ((status = 'DRAFT') = (invoice_no IS NULL))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_finalized_check CHECK ((status = 'DRAFT') = (finalized_at IS NULL))");
        // MONTHLY invoices belong to a cycle and a billing month; ONE_OFF to neither.
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_period_check CHECK (
            (invoice_type = 'MONTHLY' AND billing_period IS NOT NULL AND billing_cycle_id IS NOT NULL AND EXTRACT(DAY FROM billing_period) = 1)
            OR (invoice_type = 'ONE_OFF' AND billing_period IS NULL AND billing_cycle_id IS NULL)
        )");
        // A void keeps its number and says when and why (FR-BIL-013).
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_void_check CHECK (status <> 'VOID' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL))");

        // INV-09 / FR-BIL-003: one non-void MONTHLY invoice per account and month.
        // Voiding frees the slot; ONE_OFF invoices are outside the rule.
        DB::statement("CREATE UNIQUE INDEX invoices_monthly_period_unique ON invoices (service_account_id, billing_period) WHERE invoice_type = 'MONTHLY' AND status <> 'VOID'");

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('item_type', 20);
            $table->string('description', 255);
            // Signed: a DISCOUNT is negative. The billed rate is kept here (INV-20).
            $table->bigInteger('amount_centavos');
            $table->foreignId('plan_id')->nullable()->constrained('service_plans')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('invoice_id');
        });

        DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_type_check CHECK (item_type IN ('SUBSCRIPTION', 'INSTALLATION', 'RECONNECTION', 'DISCOUNT', 'PENALTY', 'OTHER'))");
        DB::statement("ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_sign_check CHECK ((item_type = 'DISCOUNT' AND amount_centavos < 0) OR (item_type <> 'DISCOUNT' AND amount_centavos > 0))");

        $this->installGuards();
    }

    /**
     * INV-05 / FR-BIL-009: a finalized invoice and its items are immutable, and
     * this is enforced here, for every database role. A DRAFT invoice may still
     * be edited or discarded. What a numbered invoice keeps changing is only
     * its status (recomputed from balance) and, on a void, voided_at and
     * void_reason.
     */
    private function installGuards(): void
    {
        AppendOnly::installFunction();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bcis_guard_invoice() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'DELETE on invoices is not allowed: a finalized invoice is append-only'
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status <> 'DRAFT' AND (
                    NEW.status = 'DRAFT'
                    OR NEW.invoice_no IS DISTINCT FROM OLD.invoice_no
                    OR NEW.billing_cycle_id IS DISTINCT FROM OLD.billing_cycle_id
                    OR NEW.service_account_id IS DISTINCT FROM OLD.service_account_id
                    OR NEW.subscriber_id IS DISTINCT FROM OLD.subscriber_id
                    OR NEW.invoice_type IS DISTINCT FROM OLD.invoice_type
                    OR NEW.billing_period IS DISTINCT FROM OLD.billing_period
                    OR NEW.invoice_date IS DISTINCT FROM OLD.invoice_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.total_centavos IS DISTINCT FROM OLD.total_centavos
                    OR NEW.finalized_at IS DISTINCT FROM OLD.finalized_at
                    OR (OLD.status = 'VOID' AND (
                        NEW.status <> 'VOID'
                        OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
                        OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
                    ))
                ) THEN
                    RAISE EXCEPTION 'UPDATE on invoices is not allowed: a finalized invoice is append-only'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoices_guard
                BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW EXECUTE FUNCTION bcis_guard_invoice();

            CREATE OR REPLACE FUNCTION bcis_guard_invoice_item() RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                -- The old parent matters for UPDATE and DELETE, the new one for INSERT
                -- and for an UPDATE that moves the item to another invoice.
                IF TG_OP <> 'INSERT' THEN
                    SELECT status INTO parent_status FROM invoices WHERE id = OLD.invoice_id;
                    IF parent_status IS NOT NULL AND parent_status <> 'DRAFT' THEN
                        RAISE EXCEPTION '% on invoice_items is not allowed: the invoice is finalized and append-only', TG_OP
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                    SELECT status INTO parent_status FROM invoices WHERE id = NEW.invoice_id;
                    IF parent_status IS NOT NULL AND parent_status <> 'DRAFT' THEN
                        RAISE EXCEPTION '% on invoice_items is not allowed: the invoice is finalized and append-only', TG_OP
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                    RETURN NEW;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoice_items_guard
                BEFORE INSERT OR UPDATE OR DELETE ON invoice_items
                FOR EACH ROW EXECUTE FUNCTION bcis_guard_invoice_item();

            CREATE TRIGGER invoices_no_truncate
                BEFORE TRUNCATE ON invoices
                FOR EACH STATEMENT EXECUTE FUNCTION bcis_reject_modification();
            CREATE TRIGGER invoice_items_no_truncate
                BEFORE TRUNCATE ON invoice_items
                FOR EACH STATEMENT EXECUTE FUNCTION bcis_reject_modification();
            SQL);

        // The runtime role must still UPDATE invoices (status) and DELETE drafts,
        // so only TRUNCATE is revoked here. The triggers do the row-level work.
        // The role is absent on a machine set up without scripts/db-setup.sql.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bcis_app') THEN
                    REVOKE TRUNCATE ON invoices, invoice_items, billing_cycles FROM bcis_app;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS bcis_guard_invoice_item()');
        DB::unprepared('DROP FUNCTION IF EXISTS bcis_guard_invoice()');
    }
};
