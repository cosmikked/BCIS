<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-SUB-005, FR-SUB-006, INV-01, INV-20. Prices are integer centavos. A plan is
// retired with is_active; service accounts copy the rate when they are created,
// so editing price_centavos here never changes an existing account.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 50);
        });

        // Reference data the application cannot work without, so it ships with
        // the schema instead of the demo seeder.
        DB::table('service_types')->insert([
            ['code' => 'INTERNET', 'name' => 'Internet'],
            ['code' => 'CABLE', 'name' => 'Cable'],
            ['code' => 'COMBO', 'name' => 'Combo'],
        ]);

        Schema::create('service_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->foreignId('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->bigInteger('price_centavos');
            $table->bigInteger('installation_fee_centavos')->default(0);
            $table->unsignedInteger('speed_mbps')->nullable();
            $table->unsignedInteger('channel_count')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('service_type_id');
        });

        DB::statement('ALTER TABLE service_plans ADD CONSTRAINT service_plans_price_check CHECK (price_centavos > 0 AND installation_fee_centavos >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_plans');
        Schema::dropIfExists('service_types');
    }
};
