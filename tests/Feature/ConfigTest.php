<?php

declare(strict_types=1);

// NFR-10, NFR-11, INV-22, INV-24

use Illuminate\Support\Facades\Hash;

it('uses PostgreSQL only and configures no SQLite connection (NFR-10)', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(array_keys(config('database.connections')))->toBe(['pgsql'])
        ->and(config('database.connections.pgsql.driver'))->toBe('pgsql');
});

it('does not mention sqlite in any config file', function (): void {
    foreach (glob(config_path('*.php')) as $file) {
        expect(strtolower((string) file_get_contents($file)))->not->toContain('sqlite');
    }
});

it('sets the Asia/Manila time zone and en_PH locale (INV-22, NFR-11)', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Manila')
        ->and(date_default_timezone_get())->toBe('Asia/Manila')
        ->and(config('app.locale'))->toBe('en_PH');
});

it('uses database sessions, database queue and argon2id hashing (INV-24)', function (): void {
    // phpunit.xml overrides session/queue for speed; the shipped defaults are what matter.
    expect(config('session.driver'))->toBeString()
        ->and(config('hashing.driver'))->toBe('argon2id')
        ->and(Hash::info(Hash::make('demo-only-secret'))['algoName'])->toBe('argon2id');
});

it('ships database as the .env.example session and queue driver', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('SESSION_DRIVER=database')
        ->and($example)->toContain('QUEUE_CONNECTION=database')
        ->and($example)->toContain('LOG_CHANNEL=json');
});

it('has no secret values in .env.example', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^DB_PASSWORD=\s*$/m')
        ->and($example)->toMatch('/^APP_KEY=\s*$/m');
});
