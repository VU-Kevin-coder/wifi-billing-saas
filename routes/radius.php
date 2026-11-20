<?php

use App\Http\Controllers\Api\RadiusPolicyController;
use App\Http\Middleware\AuthenticateRadiusPolicyService;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateRadiusPolicyService::class, 'throttle:120,1'])
    ->prefix('internal/radius/v1')
    ->group(function (): void {
        Route::post('/client-lookup', [RadiusPolicyController::class, 'clientLookup']);
        Route::post('/authorize', [RadiusPolicyController::class, 'authorize']);
        Route::post('/accounting-events', [RadiusPolicyController::class, 'accountingEvent']);
        Route::post('/post-auth-events', [RadiusPolicyController::class, 'postAuthEvent']);
    });
