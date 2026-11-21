<?php

use App\Http\Middleware\EnsureIdempotencyKey;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireRecentMfa;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // This authenticated service boundary intentionally has no /api
            // prefix and is registered separately from public API contracts.
            Route::middleware('api')->group(base_path('routes/radius.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
            EnsureUserIsActive::class,
            SetTenantContext::class,
            RequireTwoFactor::class,
        ]);

        $middleware->priority([
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            Authenticate::class,
            SetTenantContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        // Register the role alias
        $middleware->alias([
            'role' => EnsureRole::class,
            'idempotency' => EnsureIdempotencyKey::class,
            'mfa.recent' => RequireRecentMfa::class,
            'tenant.active' => EnsureTenantIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The control form uses literal dotted secret keys inside settings[].
        // Do not flash that entire nested payload on validation failures.
        $exceptions->dontFlash([
            'settings', 'smtp_password', 'sms_api_key', 'whatsapp_api_key', 'whatsapp_webhook_secret',
            'api_password', 'mikrotik_password', 'pesapal_consumer_key', 'pesapal_consumer_secret',
            'flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_encryption_key', 'flutterwave_webhook_secret',
            'mtn_subscription_key', 'mtn_api_user', 'mtn_api_key', 'airtel_client_id', 'airtel_client_secret',
            'token', 'code', 'email_code', 'recovery_code',
        ]);
    })->create();
