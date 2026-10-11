<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\SessionLockController;
use App\Http\Controllers\SubscriberDocumentController;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users;
use App\Livewire\Billing\Adjustments;
use App\Livewire\Billing\CurrentBilling;
use App\Livewire\Billing\GenerateBilling;
use App\Livewire\Billing\InvoiceDetail;
use App\Livewire\Billing\Invoices;
use App\Livewire\Security\ChangePassword;
use App\Livewire\Security\Login;
use App\Livewire\Services\Plans;
use App\Livewire\Services\ServiceAccounts;
use App\Livewire\Services\ServiceHistory;
use App\Livewire\Services\ServiceJobs;
use App\Livewire\Subscribers\SubscriberForm;
use App\Livewire\Subscribers\SubscriberList;
use App\Livewire\Subscribers\SubscriberProfile;
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
| PLANS, SUBSCRIBERS AND SERVICE ACCOUNTS (Phase 3)
|--------------------------------------------------------------------------
| Reading needs subscriber.view; creating and editing a subscriber needs
| subscriber.manage. Changing a service status, assigning a collector or
| editing a plan is checked again inside the Livewire component and the Action.
| There is deliberately NO delete route for subscribers, service accounts or
| plans: they are retired with a status (INV-19).
*/
Route::middleware(['auth', 'permission:subscriber.view'])->group(function (): void {
    Route::get('/subscribers', SubscriberList::class)->name('subscribers.index');
    Route::get('/subscribers/{subscriber}', SubscriberProfile::class)->whereNumber('subscriber')->name('subscribers.show');
    Route::get('/plans', Plans::class)->name('plans.index');
    Route::get('/service-accounts', ServiceAccounts::class)->name('service-accounts.index');
    Route::get('/service-history', ServiceHistory::class)->name('service-history.index');
    // The only way to read a stored document: private disk, no public URL (FR-SUB-010).
    Route::get('/subscriber-documents/{document}', SubscriberDocumentController::class)->whereNumber('document')->name('subscriber-documents.show');
});

// The Technician's list of jobs: operational fields only, no rates (FR-SUB-013).
// service.view is held by every role; what it shows is limited by the query itself.
Route::middleware(['auth', 'permission:service.view'])->get('/services', ServiceJobs::class)->name('services.operational');

Route::middleware(['auth', 'permission:subscriber.manage'])->group(function (): void {
    Route::get('/subscribers/new', SubscriberForm::class)->name('subscribers.create');
    Route::get('/subscribers/{subscriber}/edit', SubscriberForm::class)->whereNumber('subscriber')->name('subscribers.edit');
});

/*
|--------------------------------------------------------------------------
| BILLING (Phase 4)
|--------------------------------------------------------------------------
| Viewing cycles and invoices needs billing.view. Generating, discarding and
| finalizing a month needs billing.generate. Adjustments and voids are checked
| per kind (invoice.adjust, invoice.void) inside the Action as well.
*/
Route::middleware(['auth', 'permission:billing.view'])->group(function (): void {
    Route::get('/billing', CurrentBilling::class)->name('billing.current');
    Route::get('/billing/invoices', Invoices::class)->name('billing.invoices');
    Route::get('/billing/invoices/{invoice}', InvoiceDetail::class)->whereNumber('invoice')->name('billing.invoices.show');
});
// Requests are decided by a different user; each kind is checked again per request (invoice.adjust / invoice.void).
Route::middleware(['auth', 'permission:invoice.adjust'])->get('/billing/adjustments', Adjustments::class)->name('billing.adjustments');
Route::middleware(['auth', 'permission:billing.generate'])->get('/billing/generate', GenerateBilling::class)->name('billing.generate');

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
