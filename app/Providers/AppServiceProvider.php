<?php

namespace App\Providers;

use App\Auth\TenantAwareEloquentUserProvider;
use App\Models\AuditLog;
use App\Services\PlatformMailService;
use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function ($app) {
            return new TenantContext;
        });
    }

    public function boot(): void
    {
        Auth::provider('tenant-aware-eloquent', function ($app, array $config) {
            return new TenantAwareEloquentUserProvider(
                $app['hash'],
                $config['model'],
                $app->make(TenantContext::class),
            );
        });

        $this->registerAuthAuditing();
        $this->registerRateLimiters();

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));
        });

        try {
            if (Schema::hasTable('policy_settings')) {
                $ttl = (int) Policy::get('security.password_reset_ttl', 60);
                config(['auth.passwords.users.expire' => $ttl]);
            }
        } catch (\Throwable $e) {
            // Ignore during early bootstrap
        }

        try {
            if (Schema::hasTable('platform_settings')) {
                PlatformMailService::configureMailer();
            }
        } catch (\Throwable $e) {
            // Ignore during early bootstrap or when database is not yet ready
        }
    }

    /**
     * Register named limits for authentication and captive portal endpoints.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));
        RateLimiter::for('auth-password-email', fn (Request $request) => Limit::perMinute(5)->by('pwd-email:'.$request->ip()));
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(10)->by('pwd-reset:'.$request->ip()));

        RateLimiter::for('portal-browse', fn (Request $request) => Limit::perMinute(120)->by('browse:'.$request->ip()));
        RateLimiter::for('portal-redeem', fn (Request $request) => Limit::perMinute(30)->by('redeem:'.$request->ip()));
        RateLimiter::for('portal-purchase', fn (Request $request) => Limit::perMinute(30)->by('purchase:'.$request->ip()));

        RateLimiter::for('payment-poll', function (Request $request) {
            $payment = (string) ($request->route('token') ?? $request->route('transaction') ?? '');

            return [
                Limit::perMinute(60)->by('poll:'.hash('sha256', $payment.'|'.$request->ip())),
                Limit::perMinute(600)->by('poll-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('payment-activate', fn (Request $request) => [
            Limit::perMinute(10)->by('activate:'.hash('sha256', (string) $request->route('token').'|'.$request->ip())),
            Limit::perMinute(120)->by('activate-ip:'.$request->ip()),
        ]);
    }

    /**
     * Record authentication events. Never stores passwords; an audit failure
     * must never break a login.
     */
    private function registerAuthAuditing(): void
    {
        $safely = function (callable $record): void {
            try {
                $record();
            } catch (\Throwable $e) {
                Log::warning('Unable to write auth audit entry.', ['exception' => $e::class]);
            }
        };

        Event::listen(Login::class, fn (Login $e) => $safely(
            fn () => AuditLog::record('auth.login', $e->user, [], $e->user)
        ));

        Event::listen(Failed::class, fn (Failed $e) => $safely(
            fn () => AuditLog::record('auth.login_failed', null, [
                'email' => (string) ($e->credentials['email'] ?? ''),
                'known_user' => $e->user !== null,
            ])
        ));

        Event::listen(Lockout::class, fn (Lockout $e) => $safely(
            fn () => AuditLog::record('auth.lockout', null, ['email' => (string) $e->request->input('email')])
        ));

        Event::listen(PasswordReset::class, fn (PasswordReset $e) => $safely(
            fn () => AuditLog::record('auth.password_reset', $e->user, [], $e->user)
        ));
    }
}
