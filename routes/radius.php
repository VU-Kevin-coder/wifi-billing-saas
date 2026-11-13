<?php

use App\Http\Controllers\Api\RadiusPolicyController;
use App\Http\Middleware\AuthenticateRadiusPolicyService;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateRadiusPolicyService::class, 'throttle:120,1'])
    ->prefix('internal/radius/v1')
    ->group(function (): void {
        Route::post('/authorize', [RadiusPolicyController::class, 'authorize']);
        Route::post('/accounting-events', [RadiusPolicyController::class, 'accountingEvent']);
        Route::get('/disconnect-commands', [RadiusPolicyController::class, 'disconnectCommands']);
        Route::post('/disconnect-commands/{command_id}/ack', [RadiusPolicyController::class, 'acknowledgeDisconnect'])
            ->whereUuid('command_id');
    });
