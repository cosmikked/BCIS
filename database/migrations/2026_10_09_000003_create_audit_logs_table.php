<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-SEC-009, FR-SEC-010, INV-18. Audit rows are insert-only: the runtime role
// loses UPDATE/DELETE/TRUNCATE and a trigger rejects them for every role.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            // Stamped by the database clock, never by the client (INV-22).
            $table->timestampTz('occurred_at')->useCurrent();
            // Null only for events with no known user, such as a failed login.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('reason')->nullable();
            // Never put passwords, tokens or payment credentials in these (INV-24).
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('request_id', 64)->nullable();

            $table->index('occurred_at');
            $table->index(['entity_type', 'entity_id']);
            $table->index('actor_user_id');
            $table->index('action');
        });

        AppendOnly::guard('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS bcis_reject_modification()');
    }
};
