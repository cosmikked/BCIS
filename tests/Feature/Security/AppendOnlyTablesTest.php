<?php

declare(strict_types=1);

use App\Models\ApplicationSettingChange;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// FR-SEC-009, FR-SEC-013, INV-18

uses(RefreshDatabase::class);

dataset('append-only tables', [
    'audit_logs' => ['audit_logs', fn () => ['action' => 'test.event']],
    'application_setting_changes' => ['application_setting_changes', fn () => ['key' => 'grace_days', 'new_value' => json_encode(3)]],
]);

it('rejects UPDATE, DELETE and TRUNCATE in the database, even for the table owner', function (string $table, Closure $row): void {
    DB::table($table)->insert($row());

    // Each attempt runs in a savepoint so PostgreSQL's aborted-transaction
    // state does not poison the rest of the test.
    $attempts = [
        'UPDATE' => fn () => DB::statement("UPDATE {$table} SET id = id"),
        'DELETE' => fn () => DB::statement("DELETE FROM {$table}"),
        'TRUNCATE' => fn () => DB::statement("TRUNCATE {$table}"),
    ];

    foreach ($attempts as $operation => $attempt) {
        expect(fn () => DB::transaction($attempt))
            ->toThrow(QueryException::class, 'append-only');
    }

    expect(DB::table($table)->count())->toBe(1);
})->with('append-only tables');

it('has no UPDATE, DELETE or TRUNCATE privilege for the runtime role', function (string $table): void {
    $exists = DB::selectOne("SELECT 1 AS found FROM pg_roles WHERE rolname = 'bcis_app'");

    if ($exists === null) {
        $this->markTestSkipped('bcis_app is not provisioned (run scripts/db-setup.sql).');
    }

    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        $row = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', ['bcis_app', $table, $privilege]);
        expect($row->allowed)->toBeFalse("bcis_app must not have {$privilege} on {$table}");
    }

    $insert = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', ['bcis_app', $table, 'INSERT']);
    expect($insert->allowed)->toBeTrue();
})->with(['audit_logs', 'application_setting_changes']);

it('also refuses updates and deletes at the model level', function (): void {
    $log = AuditLog::create(['action' => 'test.event']);
    $change = ApplicationSettingChange::create(['key' => 'grace_days', 'new_value' => 3]);

    expect(fn () => $log->update(['action' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(fn () => $change->update(['new_value' => 9]))->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class);
});

it('stamps occurred_at on the server', function (): void {
    $log = AuditLog::create(['action' => 'test.event'])->refresh();

    expect($log->occurred_at)->not->toBeNull();
});

it('keeps foreign keys restrictive so a user with history cannot be removed', function (): void {
    $userId = DB::table('users')->insertGetId([
        'username' => 'audited', 'full_name' => 'Audited', 'password_hash' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('audit_logs')->insert(['action' => 'test.event', 'actor_user_id' => $userId]);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $userId)->delete()))
        ->toThrow(QueryException::class);
});
