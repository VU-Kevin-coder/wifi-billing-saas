<?php

use App\Http\Controllers\Operator\DashboardController as OperatorDashboard;
use App\Http\Controllers\Operator\StaffController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboard;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ─── Public / Landing Page ──────────────────────────────────
Route::get('/', function () {
    return view('landing');
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

// ─── Operator Workspace Routes ─────────────────────────────
$operatorRoutes = function () {
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

    // Live Sessions
    Route::get('/sessions', [\App\Http\Controllers\Operator\LiveSessionController::class, 'index'])->name('sessions.index');
    Route::post('/sessions/{session}/disconnect', [\App\Http\Controllers\Operator\LiveSessionController::class, 'disconnect'])->name('sessions.disconnect');
    Route::get('/sites/export', [\App\Http\Controllers\Operator\SiteController::class, 'export'])->name('sites.export');
    Route::get('/sites', [\App\Http\Controllers\Operator\SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [\App\Http\Controllers\Operator\SiteController::class, 'store'])
        ->middleware('role:operator_admin|operator_network')
        ->name('sites.store');
    Route::get('/billing', [\App\Http\Controllers\Operator\BillingController::class, 'index'])
        ->middleware('role:operator_admin|operator_finance')
        ->name('billing.index');

    // General Settings
    Route::get('/settings', [\App\Http\Controllers\Operator\SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/general', [\App\Http\Controllers\Operator\SettingsController::class, 'updateGeneral'])->name('settings.general.update');
    Route::put('/settings/hotspot', [\App\Http\Controllers\Operator\SettingsController::class, 'updateHotspot'])->name('settings.hotspot.update');
    Route::put('/settings/sms', [\App\Http\Controllers\Operator\SettingsController::class, 'updateSms'])->name('settings.sms.update');
    Route::put('/settings/whatsapp', [\App\Http\Controllers\Operator\SettingsController::class, 'updateWhatsapp'])->name('settings.whatsapp.update');
    Route::put('/settings/notifications', [\App\Http\Controllers\Operator\SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::put('/settings/security', [\App\Http\Controllers\Operator\SettingsController::class, 'updateSecurity'])->name('settings.security.update');

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
        Route::get('/team', [\App\Http\Controllers\Operator\TeamController::class, 'index'])->name('team.index');
        Route::resource('staff', StaffController::class)->only(['index', 'create', 'store']);
    });
};

$hasCustomDomain = config('app.tenant_domain') 
    && ! str_contains(config('app.tenant_domain'), 'railway.app') 
    && ! str_contains(config('app.tenant_domain'), 'localhost');

// Subdomain Routing (Active when custom domain is configured, e.g. *.hamnet.com)
if ($hasCustomDomain) {
    Route::domain('{tenant}.' . config('app.tenant_domain'))->group(function () use ($operatorRoutes) {
        Route::prefix('portal')->name('portal.')->group(function () {
            Route::get('/', [\App\Http\Controllers\PortalController::class, 'index'])->name('index');
            Route::post('/redeem', [\App\Http\Controllers\PortalController::class, 'redeem'])->name('redeem');
            Route::get('/success', [\App\Http\Controllers\PortalController::class, 'success'])->name('success');
        });

        Route::get('/sso/verify', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'ssoVerify'])
            ->name('operator.sso.verify');

        Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
            ->prefix('operator')
            ->name('operator.')
            ->group($operatorRoutes);
    });
}

// Single-Domain Routing (Active on Railway default domain, testing, localhost fallback)
Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
    ->prefix('operator')
    ->name('operator.')
    ->group($operatorRoutes);

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [\App\Http\Controllers\PortalController::class, 'index'])->name('index');
    Route::post('/redeem', [\App\Http\Controllers\PortalController::class, 'redeem'])->name('redeem');
    Route::get('/success', [\App\Http\Controllers\PortalController::class, 'success'])->name('success');
});

// ─── Breeze default dashboard (fallback redirect) ──────────
Route::get('/dashboard', function () {
    if (auth()->user()->hasRole('platform_admin')) {
        return redirect()->route('platform.dashboard');
    }
    return redirect()->route('operator.dashboard');
})->middleware(['auth'])->name('dashboard');

// ─── Authentication Routes (Breeze) ────────────────────────
// These are accessible on all domains.
Route::middleware('auth')->group(function () {
    Route::get('/profile/security', [\App\Http\Controllers\Profile\SecurityController::class, 'index'])->name('profile.security.index');
    Route::post('/profile/security/enable', [\App\Http\Controllers\Profile\SecurityController::class, 'enable'])
        ->middleware('throttle:6,1')
        ->name('profile.security.enable');
    Route::post('/profile/security/confirm', [\App\Http\Controllers\Profile\SecurityController::class, 'confirm'])
        ->middleware('throttle:6,1')
        ->name('profile.security.confirm');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
