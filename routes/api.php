<?php

use App\Http\Controllers\Api\MomoWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('webhooks')->group(function () {
    Route::post('/mtn', [MomoWebhookController::class, 'handle'])->name('api.webhooks.mtn');
});

