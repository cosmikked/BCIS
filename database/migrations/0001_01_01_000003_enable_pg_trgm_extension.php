<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// pg_trgm powers fast partial-name subscriber search (NFR-01) in later phases.
// CREATE EXTENSION needs privilege: db-setup.sql makes bcis_owner own the
// database, and pg_trgm is a trusted extension, so the owner may create it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
