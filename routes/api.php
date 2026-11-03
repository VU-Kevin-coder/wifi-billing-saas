<?php

use App\Http\Controllers\Api\FlutterwaveWebhookController;
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
});

Route::get('/payments/{token}', [PortalController::class, 'paymentStatus'])
    ->middleware('throttle:30,1')
    ->name('api.payments.status');

Route::post('/payments/{token}/activate', [PortalController::class, 'activatePayment'])
    ->middleware('throttle:10,1')
    ->name('api.payments.activate');
