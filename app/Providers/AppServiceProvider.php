<?php

namespace App\Providers;

use App\Services\PlatformMailService;
use App\Services\Tenancy\TenantContext;
use App\Auth\TenantAwareEloquentUserProvider;
use App\Models\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function ($app) {
            return new TenantContext();
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

        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $attempts = (int) \App\Services\Policy\Policy::get('security.login_throttle_attempts', 6);
            $decay = (int) \App\Services\Policy\Policy::get('security.login_throttle_decay', 1);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $attempts)
                ->by($request->input('email').'|'.$request->ip());
        });

        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($user, string $token) {
            return url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));
        });

        try {
            if (Schema::hasTable('policy_settings')) {
                $ttl = (int) \App\Services\Policy\Policy::get('security.password_reset_ttl', 60);
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
