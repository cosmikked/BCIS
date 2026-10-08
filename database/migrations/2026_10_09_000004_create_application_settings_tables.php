<?php

declare(strict_types=1);

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-SEC-013. Settings are mutable, but every change keeps old value, new value,
// who and when in an append-only history table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('application_setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at')->useCurrent();
            $table->index(['key', 'changed_at']);
        });

        AppendOnly::guard('application_setting_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('application_setting_changes');
        Schema::dropIfExists('application_settings');
    }
};
