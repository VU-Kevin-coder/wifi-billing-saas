<?php

use App\Http\Controllers\Operator\DashboardController as OperatorDashboard;
use App\Http\Controllers\Operator\StaffController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboard;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ─── Public / Landing Page ──────────────────────────────────
Route::get('/', function () {
    return view('welcome');
});

// ─── Platform Administration (Main Domain) ─────────────────
Route::middleware(['auth', 'role:platform_admin'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/dashboard', PlatformDashboard::class)->name('dashboard');
        Route::get('/impersonate/{tenant}', [PlatformDashboard::class, 'impersonate'])->name('impersonate');
        Route::get('/stop-impersonating', [PlatformDashboard::class, 'stopImpersonating'])->name('stop-impersonate');
    });

// ─── Operator Workspace (Tenant Subdomains) ────────────────
// If they visit acme.localhost:8000/operator/dashboard, it routes here.
Route::domain('{tenant}.localhost')
    ->group(function () {
        // ─── Public Captive Portal (No Auth Required) ─────────────
        Route::prefix('portal')
            ->name('portal.')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\PortalController::class, 'index'])->name('index');
                Route::post('/redeem', [\App\Http\Controllers\PortalController::class, 'redeem'])->name('redeem');
                Route::get('/success', [\App\Http\Controllers\PortalController::class, 'success'])->name('success');
            });

        // Secure single-use SSO transfer for cross-domain login
        Route::get('/sso/verify', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'ssoVerify'])
            ->name('operator.sso.verify');

        Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
            ->prefix('operator')
            ->name('operator.')
            ->group(function () {
                Route::get('/dashboard', OperatorDashboard::class)->name('dashboard');
                Route::resource('customers', \App\Http\Controllers\Operator\CustomerController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

                Route::resource('packages', \App\Http\Controllers\Operator\PackageController::class)->only(['index', 'store', 'update']);
                Route::resource('vouchers', \App\Http\Controllers\Operator\VoucherController::class)->only(['index', 'store']);
                // Router Management & Actions
                Route::post('routers/ping-all', [\App\Http\Controllers\Operator\RouterController::class, 'pingAll'])->name('routers.ping-all');
                Route::post('routers/bulk-action', [\App\Http\Controllers\Operator\RouterController::class, 'bulkAction'])->name('routers.bulk-action');
                Route::post('routers/{router}/ping', [\App\Http\Controllers\Operator\RouterController::class, 'ping'])->name('routers.ping');
                Route::post('routers/{router}/reboot', [\App\Http\Controllers\Operator\RouterController::class, 'reboot'])->name('routers.reboot');
                Route::post('routers/{router}/sync', [\App\Http\Controllers\Operator\RouterController::class, 'sync'])->name('routers.sync');
                Route::resource('routers', \App\Http\Controllers\Operator\RouterController::class);

                // Captive Portal Settings
                Route::get('/settings/portal', [\App\Http\Controllers\Operator\PortalSettingsController::class, 'edit'])->name('settings.portal');
                Route::put('/settings/portal', [\App\Http\Controllers\Operator\PortalSettingsController::class, 'update'])->name('settings.portal.update');

                // MikroTik Router Settings
                Route::get('/settings/router', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'edit'])->name('settings.router');
                Route::put('/settings/router', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'update'])->name('settings.router.update');
                Route::post('/settings/router/test', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'testConnection'])->name('settings.router.test');
                Route::post('/settings/router/sync-all', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'syncAll'])->name('settings.router.sync-all');
                Route::post('/settings/router/disconnect', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'disconnect'])->name('settings.router.disconnect');

                // Staff Management is strictly for operator_admin
                Route::middleware('role:operator_admin')->group(function () {
                    Route::resource('staff', StaffController::class)->only(['index', 'create', 'store']);
                });
            });
    });

// ─── Breeze default dashboard (fallback redirect) ──────────
Route::get('/dashboard', function () {
    if (auth()->user()->hasRole('platform_admin')) {
        return redirect()->route('platform.dashboard');
    }
    $slug = auth()->user()->tenant?->slug;
    if ($slug) {
        return redirect()->to("http://{$slug}.localhost:8000/operator/dashboard");
    }
    return redirect()->route('login');
})->middleware(['auth'])->name('dashboard');

// ─── Authentication Routes (Breeze) ────────────────────────
// These are accessible on all domains.
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
