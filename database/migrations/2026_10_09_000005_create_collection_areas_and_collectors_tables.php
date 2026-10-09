<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FR-COL-001 (tables only), INV-19. Areas and collectors are deactivated with
// is_active, never removed. A collector is a record, not a login: user_id stays
// empty because the collector app is out of scope.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('collectors', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('full_name', 150);
            $table->string('contact_no', 40)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collectors');
        Schema::dropIfExists('collection_areas');
    }
};
