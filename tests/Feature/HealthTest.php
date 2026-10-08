<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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

it('keeps only the allow-listed routes public', function (): void {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => ! collect($route->gatherMiddleware())->contains(
            fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'can:'))
        ))
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values()
        ->all();

    // Framework endpoints: Livewire's update/asset routes (each component action
    // authorizes itself, INV-17) and the local storage disk route.
    $unprotected = array_values(array_filter($unprotected, fn ($n) => ! str_starts_with($n, 'livewire.')
        && ! str_starts_with($n, 'default-livewire.')
        && ! str_starts_with($n, 'storage.')));

    expect($unprotected)->toEqualCanonicalizing(config('bcis.public_routes'));
});

it('has no unnamed route outside the framework', function (): void {
    $unnamed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->getName() === null && ! str_starts_with($route->uri(), 'livewire') && ! str_starts_with($route->uri(), 'storage'))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unnamed)->toBe([]);
});
