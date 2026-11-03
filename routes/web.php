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

// ─── Public Captive Portal (Direct / IP / Ngrok / Path Access) ─
Route::prefix('portal')
    ->name('portal.direct.')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::get('/{tenant?}', [\App\Http\Controllers\PortalController::class, 'index'])->name('index');
        Route::post('/{tenant?}/redeem', [\App\Http\Controllers\PortalController::class, 'redeem'])
            ->middleware('throttle:5,1')
            ->name('redeem');
        Route::post('/{tenant?}/purchase', [\App\Http\Controllers\PortalController::class, 'purchase'])
            ->middleware('throttle:3,1')
            ->name('purchase');
        Route::get('/{tenant?}/success', [\App\Http\Controllers\PortalController::class, 'success'])->name('success');
    });

// ─── Platform Administration (Main Domain) ─────────────────
Route::middleware(['auth', 'role:platform_admin'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/dashboard', PlatformDashboard::class)->name('dashboard');
        Route::get('/tenants', [\App\Http\Controllers\Platform\TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [\App\Http\Controllers\Platform\TenantController::class, 'store'])->name('tenants.store');
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Platform\TenantController::class, 'show'])->name('tenants.show');
        Route::get('/billing', [\App\Http\Controllers\Platform\BillingController::class, 'index'])->name('billing.index');
        Route::get('/revenue', [\App\Http\Controllers\Platform\RevenueController::class, 'index'])->name('revenue.index');
        Route::post('/revenue/withdrawals/{transaction}/approve', [\App\Http\Controllers\Platform\RevenueController::class, 'approveWithdrawal'])->name('revenue.withdrawals.approve');
        Route::post('/revenue/withdrawals/{transaction}/reject', [\App\Http\Controllers\Platform\RevenueController::class, 'rejectWithdrawal'])->name('revenue.withdrawals.reject');
        Route::post('/revenue/{tenant}/adjust', [\App\Http\Controllers\Platform\RevenueController::class, 'adjustBalance'])->name('revenue.adjust');
        Route::post('/plans', [\App\Http\Controllers\Platform\BillingController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [\App\Http\Controllers\Platform\BillingController::class, 'updatePlan'])->name('plans.update');
        Route::delete('/plans/{plan}', [\App\Http\Controllers\Platform\BillingController::class, 'destroyPlan'])->name('plans.destroy');
        Route::get('/configuration', [\App\Http\Controllers\Platform\ConfigurationController::class, 'index'])->name('configuration.index');
        Route::put('/configuration/general', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateGeneral'])->name('configuration.general.update');
        Route::put('/configuration/security', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateSecurity'])->name('configuration.security.update');
        Route::put('/configuration/flutterwave', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateFlutterwave'])->name('configuration.flutterwave.update');
        Route::put('/configuration/email', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateEmail'])->name('configuration.email.update');
        Route::post('/configuration/email/test', [\App\Http\Controllers\Platform\ConfigurationController::class, 'testEmail'])->name('configuration.email.test');
        Route::put('/configuration/sms', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateSms'])->name('configuration.sms.update');
        Route::post('/configuration/sms/test', [\App\Http\Controllers\Platform\ConfigurationController::class, 'testSms'])->name('configuration.sms.test');
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
            ->middleware('throttle:20,1')
            ->group(function () {
                Route::get('/', [\App\Http\Controllers\PortalController::class, 'index'])->name('index');
                Route::post('/redeem', [\App\Http\Controllers\PortalController::class, 'redeem'])
                    ->middleware('throttle:5,1')
                    ->name('redeem');
                Route::post('/purchase', [\App\Http\Controllers\PortalController::class, 'purchase'])
                    ->middleware('throttle:3,1')
                    ->name('purchase');
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
                Route::post('routers/{router}/test', [\App\Http\Controllers\Operator\RouterController::class, 'testConnection'])->name('routers.test');
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

                // Operator Wallet & Withdrawals
                Route::get('/wallet', [\App\Http\Controllers\Operator\WalletController::class, 'index'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('wallet.index');
                Route::post('/wallet/withdraw', [\App\Http\Controllers\Operator\WithdrawalController::class, 'store'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('wallet.withdraw');

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
            });
    });

// ─── Breeze default dashboard (fallback redirect) ──────────
Route::get('/dashboard', function () {
    if (auth()->user()->hasRole('platform_admin')) {
        return redirect()->route('platform.tenants.index');
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
