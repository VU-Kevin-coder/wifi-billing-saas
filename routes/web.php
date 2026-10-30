<?php

use App\Http\Controllers\Operator\DashboardController as OperatorDashboard;
use App\Http\Controllers\Operator\StaffController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboard;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ─── Operator Workspace ─────────────────────────────────────
Route::middleware(['auth', 'role:operator_admin|operator_finance|operator_support|operator_network'])
    ->prefix('operator')
    ->name('operator.')
    ->group(function () {
        Route::get('/dashboard', OperatorDashboard::class)->name('dashboard');
        Route::resource('staff', StaffController::class)->only(['index', 'create', 'store']);
    });

// ─── Platform Administration ────────────────────────────────
Route::middleware(['auth', 'role:platform_admin'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/dashboard', PlatformDashboard::class)->name('dashboard');
        Route::get('/impersonate/{tenant}', [PlatformDashboard::class, 'impersonate'])->name('impersonate');
        Route::get('/stop-impersonating', [PlatformDashboard::class, 'stopImpersonating'])->name('stop-impersonate');
    });

// ─── Breeze default dashboard (kept for fallback) ──────────
Route::get('/dashboard', function () {
    if (auth()->user()->hasRole('platform_admin')) {
        return redirect()->route('platform.dashboard');
    }
    return redirect()->route('operator.dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
