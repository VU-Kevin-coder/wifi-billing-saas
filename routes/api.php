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

Route::get('/payments/{token}', [PortalController::class, 'paymentStatus'])
    ->middleware('throttle:payment-poll')
    ->name('api.payments.status');

Route::post('/payments/{token}/activate', [PortalController::class, 'activatePayment'])
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
