<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-BIL-009, FR-BIL-013, INV-01, INV-05, INV-10, INV-19. PRD 19 #8 (maker-checker).
//
// An adjustment (or a void, kind VOID) is a REQUEST first. One user requests it,
// a different user approves it, and only the approval writes the ledger
// counter-entry. PENDING rows never touch a balance.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('adjustment_no', 32)->unique();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->foreignId('service_account_id')->constrained('service_accounts')->restrictOnDelete();
            // Every adjustment and void in this phase acts on one invoice. The ERD
            // allowed null for account-level adjustments; none exist yet.
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount_centavos');
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            // Why a request was rejected (or a note left by the approver).
            $table->text('decision_note')->nullable();

            $table->index(['invoice_id', 'status']);
            $table->index(['subscriber_id', 'status']);
            $table->index('status');
        });

        DB::statement('CREATE SEQUENCE adjustment_no_seq OWNED BY adjustments.adjustment_no');

        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_kind_check CHECK (kind IN ('DEBIT', 'CREDIT', 'WRITE_OFF', 'VOID'))");
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_status_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED'))");
        DB::statement('ALTER TABLE adjustments ADD CONSTRAINT adjustments_amount_check CHECK (amount_centavos > 0)');
        DB::statement('ALTER TABLE adjustments ADD CONSTRAINT adjustments_reason_check CHECK (length(btrim(reason)) >= 3)');
        // Maker-checker in the database: whoever decides is not whoever asked.
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_decision_check CHECK (
            (status = 'PENDING' AND approved_by IS NULL AND decided_at IS NULL)
            OR (status = 'APPROVED' AND approved_by IS NOT NULL AND approved_by <> requested_by AND decided_at IS NOT NULL)
            OR (status = 'REJECTED' AND approved_by IS NOT NULL AND approved_by <> requested_by AND decided_at IS NOT NULL AND decision_note IS NOT NULL)
        )");

        AppendOnly::installFunction();
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bcis_guard_adjustment() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'DELETE on adjustments is not allowed: the table is append-only'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                -- A decided adjustment is final. A pending one may only be decided:
                -- what was requested can never be rewritten.
                IF OLD.status <> 'PENDING' OR (
                    NEW.adjustment_no IS DISTINCT FROM OLD.adjustment_no
                    OR NEW.subscriber_id IS DISTINCT FROM OLD.subscriber_id
                    OR NEW.service_account_id IS DISTINCT FROM OLD.service_account_id
                    OR NEW.invoice_id IS DISTINCT FROM OLD.invoice_id
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.amount_centavos IS DISTINCT FROM OLD.amount_centavos
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.requested_at IS DISTINCT FROM OLD.requested_at
                ) THEN
                    RAISE EXCEPTION 'UPDATE on adjustments is not allowed: a decided adjustment is append-only'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER adjustments_guard
                BEFORE UPDATE OR DELETE ON adjustments
                FOR EACH ROW EXECUTE FUNCTION bcis_guard_adjustment();
            CREATE TRIGGER adjustments_no_truncate
                BEFORE TRUNCATE ON adjustments
                FOR EACH STATEMENT EXECUTE FUNCTION bcis_reject_modification();
            SQL);

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bcis_app') THEN
                    REVOKE DELETE, TRUNCATE ON adjustments FROM bcis_app;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
        DB::unprepared('DROP FUNCTION IF EXISTS bcis_guard_adjustment()');
    }
};
