<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\SessionLockController;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users;
use App\Livewire\Security\ChangePassword;
use App\Livewire\Security\Login;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EXPLICIT PUBLIC ROUTES (no authentication)
|--------------------------------------------------------------------------
| Keep this list in sync with config/bcis.php 'public_routes'. A test fails
| if a route is added here without being listed there.
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/login', Login::class)->middleware('guest')->name('login');

/*
|--------------------------------------------------------------------------
| ADMINISTRATION (permission-protected)
|--------------------------------------------------------------------------
| Each route names the permission it needs. Livewire components inside also
| authorize every method; the route check is only the first of two.
*/
Route::middleware(['auth', 'permission:user.manage'])->get('/admin/users', Users::class)->name('admin.users');
Route::middleware(['auth', 'permission:role.manage'])->get('/admin/roles', Roles::class)->name('admin.roles');
Route::middleware(['auth', 'permission:settings.manage'])->get('/admin/settings', Settings::class)->name('admin.settings');
Route::middleware(['auth', 'permission:audit.view'])->get('/admin/audit-log', AuditLogViewer::class)->name('admin.audit');

/*
|--------------------------------------------------------------------------
| SIGNED-IN ROUTES FOR ANY USER
|--------------------------------------------------------------------------
| No specific permission, but the user must be signed in. Keep this list in
| sync with config/bcis.php 'authenticated_routes'. Everything else in the
| app uses ->middleware(['auth', 'permission:<code>']).
*/
Route::middleware('auth')->group(function (): void {
    Route::view('/', 'home')->name('home');
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/password/change', ChangePassword::class)->name('password.change');

    Route::post('/session/heartbeat', [SessionLockController::class, 'heartbeat'])->name('session.heartbeat');
    Route::post('/session/lock', [SessionLockController::class, 'lock'])->name('session.lock');
    Route::post('/session/unlock', [SessionLockController::class, 'unlock'])->name('session.unlock');
});
