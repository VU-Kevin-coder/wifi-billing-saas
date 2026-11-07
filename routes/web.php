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
        Route::get('/{tenant}/pesapal/callback', [\App\Http\Controllers\PortalController::class, 'pesapalCallback'])->name('pesapal.callback');
        Route::get('/{tenant}/pending/{transaction}', [\App\Http\Controllers\PortalController::class, 'pending'])
            ->whereNumber('transaction')
            ->name('pending');
        Route::get('/{tenant}/check/{transaction}', [\App\Http\Controllers\PortalController::class, 'check'])
            ->whereNumber('transaction')
            ->name('check');
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
        Route::post('/revenue/withdrawals/{transaction}/approve', [\App\Http\Controllers\Platform\RevenueController::class, 'approveWithdrawal'])->whereNumber('transaction')->middleware('mfa.recent')->name('revenue.withdrawals.approve');
        Route::post('/revenue/withdrawals/{transaction}/reject', [\App\Http\Controllers\Platform\RevenueController::class, 'rejectWithdrawal'])->whereNumber('transaction')->middleware('mfa.recent')->name('revenue.withdrawals.reject');
        Route::post('/revenue/{tenant}/adjust', [\App\Http\Controllers\Platform\RevenueController::class, 'adjustBalance'])->middleware('mfa.recent')->name('revenue.adjust');
        Route::post('/plans', [\App\Http\Controllers\Platform\BillingController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [\App\Http\Controllers\Platform\BillingController::class, 'updatePlan'])->name('plans.update');
        Route::delete('/plans/{plan}', [\App\Http\Controllers\Platform\BillingController::class, 'destroyPlan'])->name('plans.destroy');
        Route::get('/configuration', [\App\Http\Controllers\Platform\ConfigurationController::class, 'index'])->name('configuration.index');
        Route::put('/configuration/general', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateGeneral'])->name('configuration.general.update');
        Route::put('/configuration/security', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateSecurity'])->name('configuration.security.update');
        Route::put('/configuration/pesapal', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updatePesapal'])->middleware('mfa.recent')->name('configuration.pesapal.update');
        Route::put('/configuration/flutterwave', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateFlutterwave'])->middleware('mfa.recent')->name('configuration.flutterwave.update');
        Route::put('/configuration/mtn', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateMtn'])->middleware('mfa.recent')->name('configuration.mtn.update');
        Route::put('/configuration/airtel', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateAirtel'])->middleware('mfa.recent')->name('configuration.airtel.update');
        Route::put('/configuration/email', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateEmail'])->name('configuration.email.update');
        Route::post('/configuration/email/test', [\App\Http\Controllers\Platform\ConfigurationController::class, 'testEmail'])->name('configuration.email.test');
        Route::put('/configuration/sms', [\App\Http\Controllers\Platform\ConfigurationController::class, 'updateSms'])->name('configuration.sms.update');
        Route::post('/configuration/sms/test', [\App\Http\Controllers\Platform\ConfigurationController::class, 'testSms'])->name('configuration.sms.test');
        Route::post('/impersonate/{tenant}', [PlatformDashboard::class, 'impersonate'])->middleware('mfa.recent')->name('impersonate');
        Route::post('/stop-impersonating', [PlatformDashboard::class, 'stopImpersonating'])->name('stop-impersonate');
    });

// ─── Operator Workspace (Tenant Subdomains) ────────────────
// If they visit acme.localhost:8000/operator/dashboard, it routes here.
Route::domain('{tenant}.'.config('tenancy.base_domain', 'localhost'))
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
                Route::get('/pesapal/callback', [\App\Http\Controllers\PortalController::class, 'pesapalCallback'])->name('pesapal.callback');
                Route::get('/pending/{transaction}', [\App\Http\Controllers\PortalController::class, 'pending'])
                    ->whereNumber('transaction')
                    ->name('pending');
                Route::get('/check/{transaction}', [\App\Http\Controllers\PortalController::class, 'check'])
                    ->whereNumber('transaction')
                    ->name('check');
            });

        // Secure single-use SSO transfer for cross-domain login
        Route::get('/sso/verify', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'ssoVerify'])
            ->name('operator.sso.verify');

        Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
            ->prefix('operator')
            ->name('operator.')
            ->group(function () {
                Route::get('/dashboard', OperatorDashboard::class)->name('dashboard');

                Route::middleware('role:operator_admin|operator_support')->group(function () {
                    Route::resource('customers', \App\Http\Controllers\Operator\CustomerController::class)
                        ->only(['index', 'show', 'store', 'update', 'destroy']);
                });

                Route::middleware('role:operator_admin')->group(function () {
                    Route::resource('packages', \App\Http\Controllers\Operator\PackageController::class)->only(['index', 'store', 'update']);
                    Route::resource('vouchers', \App\Http\Controllers\Operator\VoucherController::class)->only(['index', 'store']);
                });

                Route::middleware('role:operator_admin|operator_network')->group(function () {
                    Route::post('routers/ping-all', [\App\Http\Controllers\Operator\RouterController::class, 'pingAll'])->name('routers.ping-all');
                    Route::post('routers/bulk-action', [\App\Http\Controllers\Operator\RouterController::class, 'bulkAction'])->name('routers.bulk-action');
                    Route::post('routers/{router}/ping', [\App\Http\Controllers\Operator\RouterController::class, 'ping'])->name('routers.ping');
                    Route::post('routers/{router}/reboot', [\App\Http\Controllers\Operator\RouterController::class, 'reboot'])->name('routers.reboot');
                    Route::post('routers/{router}/sync', [\App\Http\Controllers\Operator\RouterController::class, 'sync'])->name('routers.sync');
                    Route::get('routers/{router}/provision-script', [\App\Http\Controllers\Operator\RouterController::class, 'provisionScript'])->name('routers.provision-script');
                    Route::post('routers/{router}/provision', [\App\Http\Controllers\Operator\RouterController::class, 'provision'])->name('routers.provision');
                    Route::post('routers/{router}/test', [\App\Http\Controllers\Operator\RouterController::class, 'testConnection'])->name('routers.test');
                    Route::patch('routers/{router}/gateway', [\App\Http\Controllers\Operator\RouterController::class, 'updateGateway'])->name('routers.gateway');
                    Route::resource('routers', \App\Http\Controllers\Operator\RouterController::class);
                    Route::post('/sessions/{session}/disconnect', [\App\Http\Controllers\Operator\LiveSessionController::class, 'disconnect'])->name('sessions.disconnect');
                    Route::post('/sites', [\App\Http\Controllers\Operator\SiteController::class, 'store'])->name('sites.store');
                });

                Route::get('/sessions', [\App\Http\Controllers\Operator\LiveSessionController::class, 'index'])->name('sessions.index');
                Route::get('/sites/export', [\App\Http\Controllers\Operator\SiteController::class, 'export'])->name('sites.export');
                Route::get('/sites', [\App\Http\Controllers\Operator\SiteController::class, 'index'])->name('sites.index');
                Route::get('/billing', [\App\Http\Controllers\Operator\BillingController::class, 'index'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('billing.index');

                // Operator Wallet & Withdrawals
                Route::get('/wallet', [\App\Http\Controllers\Operator\WalletController::class, 'index'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('wallet.index');
                Route::post('/wallet/withdraw', [\App\Http\Controllers\Operator\WithdrawalController::class, 'store'])
                    ->middleware(['role:operator_admin|operator_finance', 'mfa.recent'])
                    ->name('wallet.withdraw');

                Route::middleware('role:operator_admin')->group(function () {
                    Route::get('/settings', [\App\Http\Controllers\Operator\SettingsController::class, 'index'])->name('settings.index');
                    Route::put('/settings/general', [\App\Http\Controllers\Operator\SettingsController::class, 'updateGeneral'])->name('settings.general.update');
                    Route::put('/settings/hotspot', [\App\Http\Controllers\Operator\SettingsController::class, 'updateHotspot'])->name('settings.hotspot.update');
                    Route::put('/settings/sms', [\App\Http\Controllers\Operator\SettingsController::class, 'updateSms'])->name('settings.sms.update');
                    Route::put('/settings/whatsapp', [\App\Http\Controllers\Operator\SettingsController::class, 'updateWhatsapp'])->name('settings.whatsapp.update');
                    Route::put('/settings/notifications', [\App\Http\Controllers\Operator\SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
                    Route::put('/settings/security', [\App\Http\Controllers\Operator\SettingsController::class, 'updateSecurity'])->name('settings.security.update');
                    Route::get('/settings/portal', [\App\Http\Controllers\Operator\PortalSettingsController::class, 'edit'])->name('settings.portal');
                    Route::put('/settings/portal', [\App\Http\Controllers\Operator\PortalSettingsController::class, 'update'])->name('settings.portal.update');
                });

                Route::middleware('role:operator_admin|operator_network')->group(function () {
                    Route::get('/settings/router', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'edit'])->name('settings.router');
                    Route::put('/settings/router', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'update'])->name('settings.router.update');
                    Route::post('/settings/router/test', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'testConnection'])->name('settings.router.test');
                    Route::post('/settings/router/sync-all', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'syncAll'])->name('settings.router.sync-all');
                    Route::post('/settings/router/disconnect', [\App\Http\Controllers\Operator\RouterSettingsController::class, 'disconnect'])->name('settings.router.disconnect');
                });

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
    $tenant = auth()->user()->tenant;
    if ($tenant) {
        return redirect()->to($tenant->url('operator/dashboard'));
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

// TEMPORARY: Simulate MTN Payment Success for Sandbox Testing
Route::get('/simulate-momo-success/{reference}', function ($reference) {
    abort_unless(
        config('payments.simulation_enabled', false)
            && app()->environment(['local', 'testing']),
        404
    );

    $transaction = \App\Models\Transaction::withoutGlobalScopes()
        ->where('type', 'voucher_sale')
        ->where('reference', $reference)
        ->firstOrFail();
    $tenant = \App\Models\Tenant::query()->findOrFail($transaction->tenant_id);

    if ($transaction->status === 'pending') {
        $packageId = $transaction->meta['package_id'] ?? null;
        $phone = $transaction->phone ?? $transaction->meta['phone'] ?? null;
        $package = $packageId
            ? \App\Models\Package::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->find($packageId)
            : null;

        abort_unless($package && is_string($phone) && $phone !== '', 422, 'Missing package or phone in transaction.');
        app(\App\Services\PaymentService::class)->handleSuccessfulPackagePayment(
            $tenant,
            $package,
            $phone,
            $transaction
        );
        $transaction->refresh();
    }

    abort_unless($transaction->status === 'completed', 409, 'Transaction was not completed successfully.');

    $voucherId = $transaction->meta['voucher_id'] ?? null;
    $voucher = $voucherId
        ? \App\Models\Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->find($voucherId)
        : null;
    abort_unless($voucher, 404, 'Completed payment voucher not found.');

    session()->forget('payment_token');

    return redirect()->route('portal.direct.success', ['tenant' => $tenant->slug])
        ->with('voucher_code', $voucher->code)
        ->with('mikrotik_url', \App\Support\HotspotLogin::forVoucher($voucher));
})->middleware('web');