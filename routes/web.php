<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EXPLICIT PUBLIC ROUTES (no authentication)
|--------------------------------------------------------------------------
| Keep this list in sync with config/bcis.php 'public_routes'. A test fails
| if a route is added here without being listed there.
*/
Route::get('/health', HealthController::class)->name('health');

// TEMPORARY: placeholder shell page until authentication exists in Phase 2.
Route::view('/', 'home')->name('home');
