<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\Fixtures\Livewire\AuthorizedComponent;
use Tests\Fixtures\Livewire\ExplicitlyOpenComponent;
use Tests\Fixtures\Livewire\UnauthorizedComponent;
use Tests\Support\LivewireActionInventory;
use Tests\Support\RouteInventory;

// TO-08, FR-SEC-005, INV-17

it('has no route without a permission check or an explicit public marker', function (): void {
    $violations = RouteInventory::violations(
        Route::getRoutes()->getRoutes(),
        config('bcis.public_routes'),
        config('bcis.authenticated_routes'),
    );

    expect($violations)->toBe([]);
});

it('catches a route that forgot its permission check', function (): void {
    Route::get('/rogue', fn () => 'x')->name('rogue');
    Route::middleware('auth')->get('/me', fn () => 'x')->name('me');
    Route::middleware(['auth', 'permission:payment.view'])->get('/safe', fn () => 'x')->name('safe');
    Route::middleware(['auth', 'can:payment.view'])->get('/safe-can', fn () => 'x')->name('safe.can');
    Route::get('/unnamed', fn () => 'x');

    $violations = RouteInventory::violations(Route::getRoutes()->getRoutes(), ['health', 'home'], ['stale.route']);

    expect(implode("\n", $violations))
        ->toContain("Route 'rogue'")
        ->toContain("Route 'me'")
        ->toContain("Route 'unnamed' has no name")
        ->toContain("Listed route 'stale.route'")
        ->not->toContain("Route 'safe'")
        ->not->toContain("Route 'safe.can'");
});

it('accepts an authenticated-only route only when it is listed on purpose', function (): void {
    Route::middleware('auth')->post('/logout-test', fn () => 'x')->name('logout.test');

    $unlisted = RouteInventory::violations(Route::getRoutes()->getRoutes(), ['health', 'home'], []);
    $listed = RouteInventory::violations(Route::getRoutes()->getRoutes(), ['health', 'home'], ['logout.test']);

    expect(implode("\n", $unlisted))->toContain("Route 'logout.test'")
        ->and(implode("\n", $listed))->not->toContain('logout.test');
});

it('has no Livewire component method without an authorization check', function (): void {
    $components = [];
    $dir = app_path('Livewire');

    if (is_dir($dir)) {
        foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
            $relative = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
            $components[] = 'App\\Livewire\\'.$relative;
        }
    }

    expect(LivewireActionInventory::violations($components))->toBe([]);
});

it('flags a Livewire method that forgot to authorize and accepts explicit markers', function (): void {
    expect(LivewireActionInventory::violations([AuthorizedComponent::class, ExplicitlyOpenComponent::class]))->toBe([]);

    $violations = LivewireActionInventory::violations([UnauthorizedComponent::class]);

    expect($violations)->toHaveCount(2)
        ->and(implode("\n", $violations))->toContain('mount()')->toContain('deleteEverything()');
});
