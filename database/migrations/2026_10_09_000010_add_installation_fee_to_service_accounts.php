<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-BIL-006, INV-20. PRD 19 #6: the installation fee is an item on the
// account's FIRST invoice. The amount is a snapshot on the account (0 when no
// fee applies) and installation_fee_billed_at makes sure it is charged once.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_accounts', function (Blueprint $table): void {
            $table->bigInteger('installation_fee_centavos')->default(0);
            $table->timestampTz('installation_fee_billed_at')->nullable();
        });

        DB::statement('ALTER TABLE service_accounts ADD CONSTRAINT service_accounts_installation_fee_check CHECK (installation_fee_centavos >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_accounts DROP CONSTRAINT IF EXISTS service_accounts_installation_fee_check');

        Schema::table('service_accounts', function (Blueprint $table): void {
            $table->dropColumn(['installation_fee_centavos', 'installation_fee_billed_at']);
        });
    }
};
