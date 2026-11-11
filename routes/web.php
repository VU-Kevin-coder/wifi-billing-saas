<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Operator\AnalyticsController;
use App\Http\Controllers\Operator\CustomerController;
use App\Http\Controllers\Operator\DashboardController as OperatorDashboard;
use App\Http\Controllers\Operator\LiveSessionController;
use App\Http\Controllers\Operator\PackageController;
use App\Http\Controllers\Operator\PortalSettingsController;
use App\Http\Controllers\Operator\RouterController;
use App\Http\Controllers\Operator\RouterSettingsController;
use App\Http\Controllers\Operator\SettingsController;
use App\Http\Controllers\Operator\SiteController;
use App\Http\Controllers\Operator\StaffController;
use App\Http\Controllers\Operator\TeamController;
use App\Http\Controllers\Operator\VoucherController;
use App\Http\Controllers\Operator\WalletController;
use App\Http\Controllers\Operator\WithdrawalController;
use App\Http\Controllers\Platform\AdjustmentController;
use App\Http\Controllers\Platform\BillingController;
use App\Http\Controllers\Platform\ConfigurationController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboard;
use App\Http\Controllers\Platform\PolicyController;
use App\Http\Controllers\Platform\RefundController;
use App\Http\Controllers\Platform\RevenueController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\VpnPeerController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalPlanController;
use App\Http\Controllers\Profile\SecurityController;
use App\Http\Controllers\ProfileController;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use App\Support\HotspotLogin;
use Illuminate\Support\Facades\Route;

// ─── Public / Landing Page ──────────────────────────────────
Route::get('/', function () {
    return view('landing');
});

// ─── Public Captive Portal (Direct / IP / Ngrok / Path Access) ─
Route::prefix('portal')
    ->name('portal.direct.')
    ->group(function () {
        Route::get('/{tenant?}', [PortalController::class, 'index'])
            ->where('tenant', '(?!success$|plan$)[A-Za-z0-9-]+')
            ->middleware('throttle:portal-browse')
            ->name('index');
        Route::post('/{tenant?}/redeem', [PortalController::class, 'redeem'])
            ->middleware('throttle:portal-redeem')
            ->name('redeem');
        Route::post('/{tenant?}/purchase', [PortalController::class, 'purchase'])
            ->middleware('idempotency')
            ->middleware('throttle:portal-purchase')
            ->name('purchase');
        Route::get('/{tenant?}/success', [PortalController::class, 'success'])
            ->middleware('throttle:portal-browse')
            ->name('success');
        Route::get('/{tenant}/pesapal/callback', [PortalController::class, 'pesapalCallback'])
            ->middleware('throttle:portal-browse')
            ->name('pesapal.callback');
        Route::get('/{tenant}/pending/{transaction}', [PortalController::class, 'pending'])
            ->whereNumber('transaction')
            ->middleware('throttle:portal-browse')
            ->name('pending');
        Route::get('/{tenant}/check/{transaction}', [PortalController::class, 'check'])
            ->whereNumber('transaction')
            ->middleware('throttle:payment-poll')
            ->name('check');
    });

// ─── Platform Administration (Main Domain) ─────────────────
Route::middleware(['auth', 'role:platform_admin'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/dashboard', PlatformDashboard::class)->name('dashboard');
        Route::get('/vpn-peers', [VpnPeerController::class, 'index'])->name('vpn-peers.index');
        Route::post('/vpn-peers', [VpnPeerController::class, 'store'])->name('vpn-peers.store');
        Route::get('/vpn-peers/{peer}/router-config', [VpnPeerController::class, 'download'])
            ->whereNumber('peer')->middleware('throttle:3,1')->name('vpn-peers.download');
        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::put('/tenants/{tenant}/plan', [TenantController::class, 'changePlan'])->name('tenants.change-plan');
        Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');
        Route::post('/tenants/{tenant}/verify-kyc', [TenantController::class, 'verifyKyc'])->name('tenants.verify-kyc');
        Route::post('/tenants/{tenant}/reject-kyc', [TenantController::class, 'rejectKyc'])->name('tenants.reject-kyc');
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('/revenue', [RevenueController::class, 'index'])->name('revenue.index');
        Route::get('/revenue/adjustments', [AdjustmentController::class, 'index'])->name('revenue.adjustments.index');
        Route::post('/revenue/withdrawals/{transaction}/approve', [RevenueController::class, 'approveWithdrawal'])->whereNumber('transaction')->middleware(['mfa.recent', 'idempotency'])->name('revenue.withdrawals.approve');
        Route::post('/revenue/withdrawals/{transaction}/reject', [RevenueController::class, 'rejectWithdrawal'])->whereNumber('transaction')->middleware(['mfa.recent', 'idempotency'])->name('revenue.withdrawals.reject');
        Route::post('/revenue/{tenant}/adjust', [AdjustmentController::class, 'store'])->middleware(['mfa.recent', 'idempotency'])->name('revenue.adjust');
        Route::post('/revenue/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->whereNumber('adjustment')->middleware(['mfa.recent', 'idempotency'])->name('revenue.adjustments.approve');
        Route::post('/revenue/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->whereNumber('adjustment')->middleware(['mfa.recent', 'idempotency'])->name('revenue.adjustments.reject');

        Route::get('/refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->whereNumber('refund')->middleware(['mfa.recent', 'idempotency'])->name('refunds.approve');
        Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->whereNumber('refund')->middleware(['mfa.recent', 'idempotency'])->name('refunds.reject');
        Route::post('/plans', [BillingController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [BillingController::class, 'updatePlan'])->name('plans.update');
        Route::delete('/plans/{plan}', [BillingController::class, 'destroyPlan'])->name('plans.destroy');
        Route::get('/configuration', [ConfigurationController::class, 'index'])->name('configuration.index');
        Route::put('/configuration/general', [ConfigurationController::class, 'updateGeneral'])->name('configuration.general.update');
        Route::put('/configuration/security', [ConfigurationController::class, 'updateSecurity'])->name('configuration.security.update');
        Route::put('/configuration/pesapal', [ConfigurationController::class, 'updatePesapal'])->middleware('mfa.recent')->name('configuration.pesapal.update');
        Route::put('/configuration/flutterwave', [ConfigurationController::class, 'updateFlutterwave'])->middleware('mfa.recent')->name('configuration.flutterwave.update');
        Route::put('/configuration/mtn', [ConfigurationController::class, 'updateMtn'])->middleware('mfa.recent')->name('configuration.mtn.update');
        Route::put('/configuration/airtel', [ConfigurationController::class, 'updateAirtel'])->middleware('mfa.recent')->name('configuration.airtel.update');
        Route::put('/configuration/email', [ConfigurationController::class, 'updateEmail'])->name('configuration.email.update');
        Route::post('/configuration/email/test', [ConfigurationController::class, 'testEmail'])->name('configuration.email.test');
        Route::put('/configuration/sms', [ConfigurationController::class, 'updateSms'])->name('configuration.sms.update');
        Route::post('/configuration/sms/test', [ConfigurationController::class, 'testSms'])->name('configuration.sms.test');
        Route::get('/configuration/policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::put('/configuration/policies/lifecycle', [PolicyController::class, 'updateLifecycle'])->name('policies.lifecycle.update');
        Route::put('/configuration/policies/approval', [PolicyController::class, 'updateApproval'])->name('policies.approval.update');
        Route::put('/configuration/policies/{category}', [PolicyController::class, 'updateCategory'])->name('policies.category.update');
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
            ->group(function () {
                Route::get('/', [PortalController::class, 'index'])
                    ->middleware('throttle:portal-browse')
                    ->name('index');
                Route::post('/redeem', [PortalController::class, 'redeem'])
                    ->middleware('throttle:portal-redeem')
                    ->name('redeem');
                Route::post('/purchase', [PortalController::class, 'purchase'])
                    ->middleware('idempotency')
                    ->middleware('throttle:portal-purchase')
                    ->name('purchase');
                Route::get('/success', [PortalController::class, 'success'])
                    ->middleware('throttle:portal-browse')
                    ->name('success');
                Route::get('/pesapal/callback', [PortalController::class, 'pesapalCallback'])
                    ->middleware('throttle:portal-browse')
                    ->name('pesapal.callback');
                Route::get('/pending/{transaction}', [PortalController::class, 'pending'])
                    ->whereNumber('transaction')
                    ->middleware('throttle:portal-browse')
                    ->name('pending');
                Route::get('/check/{transaction}', [PortalController::class, 'check'])
                    ->whereNumber('transaction')
                    ->middleware('throttle:payment-poll')
                    ->name('check');

                // Customer self-service: check a plan and renew it
                Route::get('/plan', [PortalPlanController::class, 'form'])->middleware('throttle:portal-browse')->name('plan');
                Route::post('/plan', [PortalPlanController::class, 'lookup'])->middleware('throttle:portal-redeem')->name('plan.lookup');
                Route::get('/r/{token}', [PortalPlanController::class, 'show'])
                    ->where('token', '[a-z0-9]{12}')
                    ->middleware('throttle:portal-redeem')
                    ->name('plan.show');
            });

        // Secure single-use SSO transfer for cross-domain login
        Route::get('/sso/verify', [AuthenticatedSessionController::class, 'ssoVerify'])
            ->name('operator.sso.verify');

        Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
            ->prefix('operator')
            ->name('operator.')
            ->group(function () {
                Route::get('/dashboard', OperatorDashboard::class)->name('dashboard');

                Route::middleware('role:operator_admin|operator_support')->group(function () {
                    Route::get('/customers/export.csv', [CustomerController::class, 'export'])
                        ->name('customers.export');
                    Route::resource('customers', CustomerController::class)
                        ->only(['index', 'show', 'update', 'destroy']);
                    Route::post('/customers', [CustomerController::class, 'store'])
                        ->middleware('idempotency')
                        ->name('customers.store');
                });

                Route::middleware('role:operator_admin')->group(function () {
                    Route::resource('packages', PackageController::class)->only(['index', 'store', 'update']);

                    // Vouchers: index + store are gated by the tenant lifecycle policy
                    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
                    Route::post('/vouchers', [VoucherController::class, 'store'])
                        ->middleware(['tenant.active:generate_vouchers', 'idempotency'])
                        ->name('vouchers.store');

                    // Voucher void: admin-only, removes a voucher from circulation
                    Route::post('vouchers/{voucher}/void', [VoucherController::class, 'void'])
                        ->whereNumber('voucher')
                        ->name('vouchers.void');
                });

                Route::middleware('role:operator_admin|operator_network')->group(function () {
                    Route::post('routers/ping-all', [RouterController::class, 'pingAll'])->name('routers.ping-all');
                    Route::post('routers/bulk-action', [RouterController::class, 'bulkAction'])->name('routers.bulk-action');
                    Route::post('routers/{router}/ping', [RouterController::class, 'ping'])->name('routers.ping');
                    Route::post('routers/{router}/reboot', [RouterController::class, 'reboot'])->name('routers.reboot');
                    Route::post('routers/{router}/sync', [RouterController::class, 'sync'])->name('routers.sync');
                    Route::get('routers/{router}/provision-script', [RouterController::class, 'provisionScript'])->name('routers.provision-script');
                    Route::post('routers/{router}/provision', [RouterController::class, 'provision'])->name('routers.provision');
                    Route::post('routers/{router}/test', [RouterController::class, 'testConnection'])->name('routers.test');
                    Route::post('routers/{router}/telemetry', [RouterController::class, 'telemetry'])->name('routers.telemetry');
                    Route::post('routers/{router}/vpn-peer', [VpnPeerController::class, 'storeForRouter'])->whereNumber('router')->name('routers.vpn-peer.store');
                    Route::get('routers/{router}/vpn-peer/config', [VpnPeerController::class, 'downloadForRouter'])->whereNumber('router')->middleware('throttle:3,1')->name('routers.vpn-peer.download');
                    Route::patch('routers/{router}/gateway', [RouterController::class, 'updateGateway'])->name('routers.gateway');
                    Route::resource('routers', RouterController::class)->except(['store']);
                    Route::post('/routers', [RouterController::class, 'store'])
                        ->middleware('idempotency')
                        ->name('routers.store');
                    Route::post('/sessions/{session}/disconnect', [LiveSessionController::class, 'disconnect'])->name('sessions.disconnect');
                    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
                });

                Route::get('/sessions', [LiveSessionController::class, 'index'])->name('sessions.index');
                Route::get('/sites/export', [SiteController::class, 'export'])->name('sites.export');
                Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
                Route::get('/billing', [App\Http\Controllers\Operator\BillingController::class, 'index'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('billing.index');

                // Operator Wallet & Withdrawals
                Route::get('/wallet', [WalletController::class, 'index'])
                    ->middleware('role:operator_admin|operator_finance')
                    ->name('wallet.index');
                Route::post('/wallet/withdraw', [WithdrawalController::class, 'store'])
                    ->middleware(['role:operator_admin|operator_finance', 'tenant.active:withdraw', 'mfa.recent', 'idempotency'])
                    ->name('wallet.withdraw');

                // Refund requests (the platform approves and pays the customer)
                Route::middleware('role:operator_admin|operator_finance')->group(function () {
                    // Analytics (read-only sales reporting)
                    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

                    Route::get('/refunds', [App\Http\Controllers\Operator\RefundController::class, 'index'])->name('refunds.index');
                    Route::post('/refunds', [App\Http\Controllers\Operator\RefundController::class, 'store'])->middleware('idempotency')->name('refunds.store');
                    Route::post('/refunds/{refund}/cancel', [App\Http\Controllers\Operator\RefundController::class, 'cancel'])->whereNumber('refund')->middleware('idempotency')->name('refunds.cancel');
                });

                Route::middleware('role:operator_admin')->group(function () {
                    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
                    Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
                    Route::put('/settings/hotspot', [SettingsController::class, 'updateHotspot'])->name('settings.hotspot.update');
                    Route::put('/settings/sms', [SettingsController::class, 'updateSms'])->name('settings.sms.update');
                    Route::put('/settings/whatsapp', [SettingsController::class, 'updateWhatsapp'])->name('settings.whatsapp.update');
                    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
                    Route::put('/settings/security', [SettingsController::class, 'updateSecurity'])->name('settings.security.update');
                    Route::get('/settings/portal', [PortalSettingsController::class, 'edit'])->name('settings.portal');
                    Route::put('/settings/portal', [PortalSettingsController::class, 'update'])->name('settings.portal.update');
                });

                Route::middleware('role:operator_admin|operator_network')->group(function () {
                    Route::get('/settings/router', [RouterSettingsController::class, 'edit'])->name('settings.router');
                    Route::put('/settings/router', [RouterSettingsController::class, 'update'])->name('settings.router.update');
                    Route::post('/settings/router/test', [RouterSettingsController::class, 'testConnection'])->name('settings.router.test');
                    Route::post('/settings/router/sync-all', [RouterSettingsController::class, 'syncAll'])->name('settings.router.sync-all');
                    Route::post('/settings/router/disconnect', [RouterSettingsController::class, 'disconnect'])->name('settings.router.disconnect');
                });

                // Staff Management is strictly for operator_admin
                Route::middleware('role:operator_admin')->group(function () {
                    Route::get('/team', [TeamController::class, 'index'])->name('team.index');
                    Route::resource('staff', StaffController::class)->only(['index', 'create', 'store']);
                    Route::patch('staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->name('staff.update');
                    Route::post('staff/{staff}/deactivate', [StaffController::class, 'deactivate'])->whereNumber('staff')->name('staff.deactivate');
                    Route::post('staff/{staff}/reactivate', [StaffController::class, 'reactivate'])->whereNumber('staff')->name('staff.reactivate');
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
    Route::get('/profile/security', [SecurityController::class, 'index'])->name('profile.security.index');
    Route::post('/profile/security/enable', [SecurityController::class, 'enable'])
        ->middleware('throttle:6,1')
        ->name('profile.security.enable');
    Route::post('/profile/security/confirm', [SecurityController::class, 'confirm'])
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

    $transaction = Transaction::withoutGlobalScopes()
        ->where('type', 'voucher_sale')
        ->where('reference', $reference)
        ->firstOrFail();
    $tenant = Tenant::query()->findOrFail($transaction->tenant_id);

    if ($transaction->status === 'pending') {
        $packageId = $transaction->meta['package_id'] ?? null;
        $phone = $transaction->phone ?? $transaction->meta['phone'] ?? null;
        $package = $packageId
            ? Package::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->find($packageId)
            : null;

        abort_unless($package && is_string($phone) && $phone !== '', 422, 'Missing package or phone in transaction.');
        app(PaymentService::class)->handleSuccessfulPackagePayment(
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
        ? Voucher::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->find($voucherId)
        : null;
    abort_unless($voucher, 404, 'Completed payment voucher not found.');

    session()->forget('payment_token');

    return redirect()->route('portal.direct.success', ['tenant' => $tenant->slug])
        ->with('voucher_code', $voucher->code)
        ->with('mikrotik_url', HotspotLogin::forVoucher($voucher));
})->middleware('web');
