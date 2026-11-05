<?php

namespace App\Providers;

use App\Services\PlatformMailService;
use App\Services\Tenancy\TenantContext;
use App\Auth\TenantAwareEloquentUserProvider;
use Illuminate\Support\Facades\Auth;
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

        try {
            if (Schema::hasTable('platform_settings')) {
                PlatformMailService::configureMailer();
            }
        } catch (\Throwable $e) {
            // Ignore during early bootstrap or when database is not yet ready
        }
    }
}
