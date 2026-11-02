<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Exclude webhooks and public captive portal endpoints from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'webhooks/*',
            'portal/*',
            'portal',
            '*/portal/*',
        ]);

        // Register the tenant context middleware globally for web routes
        $middleware->web(append: [
            SetTenantContext::class,
            RequireTwoFactor::class,
        ]);

        // Register the role alias
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
