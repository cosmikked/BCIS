<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-SUB-001, FR-SUB-003, FR-SUB-004, FR-SUB-010, INV-10, INV-19. Subscribers are
// never deleted: status column only, every foreign key RESTRICT. The account
// number comes from a PostgreSQL sequence and the unique index is the real
// duplicate guard (the application never uses max + 1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('account_no', 32)->unique();
            $table->string('full_name', 150);
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('collection_area_id')->nullable()->constrained('collection_areas')->restrictOnDelete();
            // Default due day for new service accounts; the service account's own
            // value is the one billing uses.
            $table->unsignedSmallInteger('billing_due_day');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status');
            $table->index('collection_area_id');
        });

        // OWNED BY ties the sequence to the table, so migrate:fresh drops and
        // recreates it with the table instead of failing on a leftover one.
        DB::statement('CREATE SEQUENCE subscriber_no_seq OWNED BY subscribers.account_no');

        DB::statement("ALTER TABLE subscribers ADD CONSTRAINT subscribers_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'TERMINATED', 'ARCHIVED'))");
        DB::statement('ALTER TABLE subscribers ADD CONSTRAINT subscribers_due_day_check CHECK (billing_due_day BETWEEN 1 AND 31)');
        // Trigram index for fuzzy and partial name search (FR-SUB-003, NFR-05).
        // The search query must use the same lower(full_name) expression.
        DB::statement('CREATE INDEX subscribers_full_name_trgm ON subscribers USING gin (lower(full_name) gin_trgm_ops)');

        Schema::create('subscriber_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->string('contact_type', 10);
            $table->string('value', 190);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('subscriber_id');
            $table->index('value');
        });

        DB::statement("ALTER TABLE subscriber_contacts ADD CONSTRAINT subscriber_contacts_type_check CHECK (contact_type IN ('MOBILE', 'EMAIL', 'OTHER'))");
        // Phone numbers are typed as 0917-123-4567, +63 917 123 4567 and so on.
        // The digits-only expression lets the search and the duplicate warning
        // compare them regardless of formatting.
        DB::statement("CREATE INDEX subscriber_contacts_digits ON subscriber_contacts ((regexp_replace(value, '\\D', '', 'g')))");

        Schema::create('subscriber_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            $table->string('address_type', 10);
            $table->string('line1', 190);
            $table->string('barangay', 100)->nullable();
            $table->string('city', 100);
            $table->string('landmark', 190)->nullable();
            // A service account's collection area is the area of its service address.
            $table->foreignId('collection_area_id')->nullable()->constrained('collection_areas')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('subscriber_id');
            $table->index('collection_area_id');
        });

        DB::statement("ALTER TABLE subscriber_addresses ADD CONSTRAINT subscriber_addresses_type_check CHECK (address_type IN ('BILLING', 'SERVICE'))");
        // One trigram index over the whole address text. The search query must
        // build exactly this expression for the index to be used.
        DB::statement("CREATE INDEX subscriber_addresses_text_trgm ON subscriber_addresses USING gin ((lower(line1 || ' ' || coalesce(barangay, '') || ' ' || city || ' ' || coalesce(landmark, ''))) gin_trgm_ops)");

        Schema::create('subscriber_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->restrictOnDelete();
            // Generated name on the private disk (FR-SUB-010). The name the user
            // uploaded is kept only for display and never used as a path.
            $table->string('file_path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at')->useCurrent();

            $table->index('subscriber_id');
        });

        DB::statement('ALTER TABLE subscriber_documents ADD CONSTRAINT subscriber_documents_size_check CHECK (size_bytes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_documents');
        Schema::dropIfExists('subscriber_addresses');
        Schema::dropIfExists('subscriber_contacts');
        Schema::dropIfExists('subscribers');
    }
};
