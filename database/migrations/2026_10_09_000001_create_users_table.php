<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FR-SEC-001, FR-SEC-002, FR-SEC-004, FR-SEC-012, INV-19, INV-24.
// Users are never deleted: is_active is the only off switch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 64);
            $table->string('full_name', 150);
            // argon2id hash only (INV-24). Never a plaintext value.
            $table->string('password_hash');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->timestampsTz();
        });

        // Usernames compare case-insensitively: "Maria" and "maria" are one person.
        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');

        // The sessions table (Phase 1) now has something to point at.
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::dropIfExists('users');
    }
};
