<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Operator\AnalyticsController;
use App\Http\Controllers\Operator\CustomerController;
use App\Http\Controllers\Operator\DashboardController as OperatorDashboard;
use App\Http\Controllers\Operator\LiveSessionController;
use App\Http\Controllers\Operator\PackageController;
use App\Http\Controllers\Operator\PortalSettingsController;
use App\Http\Controllers\Operator\RadiusRouterCredentialController;
use App\Http\Controllers\Operator\RouterController;
use App\Http\Controllers\Operator\RouterOnboardingController;
use App\Http\Controllers\Operator\RouterSettingsController;
use App\Http\Controllers\Operator\SettingsController;
use App\Http\Controllers\Operator\SiteController;
use App\Http\Controllers\Operator\StaffController;
use App\Http\Controllers\Operator\SubscriberController;
use App\Http\Controllers\Operator\TeamController;
use App\Http\Controllers\Operator\VoucherController;
use App\Http\Controllers\Operator\WalletController;
use App\Http\Controllers\Operator\WithdrawalController;
use App\Http\Controllers\Platform\AdjustmentController;
use App\Http\Controllers\Platform\BillingController;
use App\Http\Controllers\Platform\ConfigurationController;
use App\Http\Controllers\Platform\ControlSettingsController;
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
use App\Http\Middleware\EnsurePlatformAdminIpAllowed;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Route;

Route::get('/health/ready', [HealthController::class, 'ready'])
    ->middleware('throttle:30,1')
    ->name('health.ready');

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
Route::middleware(['auth', 'role:platform_admin', EnsurePlatformAdminIpAllowed::class])
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
        Route::get('/configuration/control', [ControlSettingsController::class, 'index'])->name('configuration.control');
        Route::put('/configuration/control', [ControlSettingsController::class, 'update'])->middleware('mfa.recent')->name('configuration.control.update');
        Route::get('/configuration/export', [ControlSettingsController::class, 'export'])->name('configuration.export');
        Route::post('/configuration/actions/{action}', [ControlSettingsController::class, 'action'])
            ->whereIn('action', ['rotate', 'purge', 'freeze', 'resume', 'reset'])
            ->middleware('mfa.recent')
            ->name('configuration.control.action');
        Route::put('/configuration/general', [ConfigurationController::class, 'updateGeneral'])->name('configuration.general.update');
        Route::put('/configuration/security', [ConfigurationController::class, 'updateSecurity'])->name('configuration.security.update');
        Route::put('/configuration/email', [ConfigurationController::class, 'updateEmail'])->middleware('mfa.recent')->name('configuration.email.update');
        Route::post('/configuration/email/test', [ConfigurationController::class, 'testEmail'])->middleware('mfa.recent')->name('configuration.email.test');
        Route::put('/configuration/sms', [ConfigurationController::class, 'updateSms'])->middleware('mfa.recent')->name('configuration.sms.update');
        Route::post('/configuration/sms/test', [ConfigurationController::class, 'testSms'])->middleware('mfa.recent')->name('configuration.sms.test');
        Route::get('/configuration/policies', [PolicyController::class, 'index'])->name('policies.index');
        Route::put('/configuration/policies', [PolicyController::class, 'updateAll'])->middleware('mfa.recent')->name('policies.update');
        Route::get('/configuration/policies/export', [PolicyController::class, 'export'])->name('policies.export');
        Route::put('/configuration/policies/lifecycle', [PolicyController::class, 'updateLifecycle'])->middleware('mfa.recent')->name('policies.lifecycle.update');
        Route::put('/configuration/policies/approval', [PolicyController::class, 'updateApproval'])->middleware('mfa.recent')->name('policies.approval.update');
        Route::put('/configuration/policies/{category}', [PolicyController::class, 'updateCategory'])->middleware('mfa.recent')->name('policies.category.update');
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
            ->group($operatorRoutes);
    });
}

// Single-Domain Routing (Active on Railway default domain, testing, localhost fallback)
Route::get('/sso/verify', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'ssoVerify'])
    ->name('operator.sso.verify');

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
        return redirect()->route('platform.tenants.index');
    }
    return redirect()->route('operator.dashboard');
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

// Local payment simulator; available only when explicitly enabled in local/testing.
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

    // The reference is not a credential: authorize before confirming or fulfilling a sale.
    $token = session('payment_token');
    abort_unless(
        is_string($token) && $token !== '' && is_string($transaction->payment_token_hash)
            && hash_equals($transaction->payment_token_hash, hash('sha256', $token)),
        404
    );

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

    return redirect()->route('portal.direct.success', ['tenant' => $tenant->slug]);
})->middleware('web');
