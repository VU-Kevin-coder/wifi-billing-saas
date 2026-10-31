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
        // Secure single-use SSO transfer for cross-domain login
        Route::get('/sso/verify', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'ssoVerify'])
            ->name('operator.sso.verify');

        Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
            ->prefix('operator')
            ->name('operator.')
            ->group(function () {
                Route::get('/dashboard', OperatorDashboard::class)->name('dashboard');

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
