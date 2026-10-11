<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-BIL-001, FR-BIL-004, FR-BIL-005, INV-01, INV-11, INV-19.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cycles', function (Blueprint $table): void {
            $table->id();
            // First day of the billed month. One cycle per month.
            $table->date('period_month')->unique();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at')->useCurrent();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->unsignedInteger('invoice_count')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE billing_cycles ADD CONSTRAINT billing_cycles_status_check CHECK (status IN ('DRAFT', 'FINALIZED'))");
        DB::statement('ALTER TABLE billing_cycles ADD CONSTRAINT billing_cycles_first_of_month_check CHECK (EXTRACT(DAY FROM period_month) = 1)');
        DB::statement('ALTER TABLE billing_cycles ADD CONSTRAINT billing_cycles_totals_check CHECK (invoice_count >= 0 AND total_centavos >= 0)');
        // A finalized cycle has who and when; a draft one has neither.
        DB::statement("ALTER TABLE billing_cycles ADD CONSTRAINT billing_cycles_finalized_check CHECK ((status = 'FINALIZED') = (finalized_at IS NOT NULL AND finalized_by IS NOT NULL))");

        // A cycle is never deleted (discarding drafts empties it, it does not
        // remove it) and a FINALIZED cycle is never edited (FR-BIL-004).
        AppendOnly::installFunction();
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bcis_guard_billing_cycle() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'DELETE on billing_cycles is not allowed: the table is append-only'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF OLD.status = 'FINALIZED' THEN
                    RAISE EXCEPTION 'UPDATE on billing_cycles is not allowed: a finalized cycle is append-only'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER billing_cycles_guard
                BEFORE UPDATE OR DELETE ON billing_cycles
                FOR EACH ROW EXECUTE FUNCTION bcis_guard_billing_cycle();
            CREATE TRIGGER billing_cycles_no_truncate
                BEFORE TRUNCATE ON billing_cycles
                FOR EACH STATEMENT EXECUTE FUNCTION bcis_reject_modification();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
        DB::unprepared('DROP FUNCTION IF EXISTS bcis_guard_billing_cycle()');
    }
};
