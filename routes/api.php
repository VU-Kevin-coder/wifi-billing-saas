<?php

use App\Http\Controllers\Api\FlutterwaveWebhookController;
use App\Http\Controllers\Api\MobileMoneyWebhookController;
use App\Http\Controllers\Api\PesapalWebhookController;
use App\Http\Controllers\Api\SiteAgentController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('webhooks')->group(function () {
    Route::match(['get', 'post'], '/pesapal', [PesapalWebhookController::class, 'handle'])
        ->name('api.webhooks.pesapal');
    Route::post('/flutterwave', [FlutterwaveWebhookController::class, 'handle'])
        ->name('api.webhooks.flutterwave');
    Route::post('/mtn', [MobileMoneyWebhookController::class, 'handleMtn'])
        ->middleware('throttle:60,1')
        ->name('api.webhooks.mtn');
    Route::post('/airtel', [MobileMoneyWebhookController::class, 'handleAirtel'])
        ->middleware('throttle:60,1')
        ->name('api.webhooks.airtel');
});

// The payment token is a bearer secret (it unlocks the voucher code), so it travels
// in the X-Payment-Token header, never in the URL where proxies, access logs and
// Referer headers would record it.
Route::get('/payments/status', [PortalController::class, 'paymentStatus'])
    ->middleware('throttle:payment-poll')
    ->name('api.payments.status');

Route::post('/payments/activate', [PortalController::class, 'activatePayment'])
    ->middleware('throttle:payment-activate')
    ->name('api.payments.activate');

Route::prefix('site-agent')
    ->middleware(['site-agent', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('/commands', [SiteAgentController::class, 'poll'])->name('api.site-agent.commands');
        Route::post('/commands/{command}/complete', [SiteAgentController::class, 'complete'])
            ->whereNumber('command')
            ->name('api.site-agent.commands.complete');
    });
