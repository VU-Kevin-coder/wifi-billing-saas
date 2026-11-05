<?php

use App\Http\Controllers\Api\FlutterwaveWebhookController;
use App\Http\Controllers\Api\MobileMoneyWebhookController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('webhooks')->group(function () {
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
    ->middleware('throttle:30,1')
    ->name('api.payments.status');

Route::post('/payments/{token}/activate', [PortalController::class, 'activatePayment'])
    ->middleware('throttle:10,1')
    ->name('api.payments.activate');
