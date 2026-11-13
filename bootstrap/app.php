<?php

use App\Http\Middleware\AuthenticateSiteAgent;
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
            // The config repository is not bootstrapped yet while middleware is configured.
            at: getenv('TRUSTED_PROXIES') ?: null,
            headers: Request::HEADER_X_FORWARDED_FOR
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
            'site-agent' => AuthenticateSiteAgent::class,
            'mfa.recent' => RequireRecentMfa::class,
            'tenant.active' => EnsureTenantIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
