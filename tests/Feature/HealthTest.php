<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// NFR-06, INV-17, INV-25

it('reports ok when the database answers', function (): void {
    $this->getJson('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'database' => 'ok'])
        ->assertJsonStructure(['status', 'database', 'time'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('reports 503 without leaking details when the database is down', function (): void {
    DB::shouldReceive('select')->andThrow(new RuntimeException('connection to server at "10.0.0.5" failed: password authentication failed'));

    $response = $this->getJson('/health')->assertStatus(503);

    expect($response->json())->toMatchArray(['status' => 'degraded', 'database' => 'unreachable'])
        ->and($response->getContent())->not->toContain('10.0.0.5')->not->toContain('password');
});

// The route inventory (public list, permission checks, unnamed routes) moved to
// tests/Feature/Security/InventoryTest.php with the Phase 2 TO-08 test.
