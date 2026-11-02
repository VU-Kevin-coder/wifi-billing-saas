<?php

namespace App\Providers;

use App\Services\PlatformMailService;
use App\Services\Tenancy\TenantContext;
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
        try {
            if (Schema::hasTable('platform_settings')) {
                PlatformMailService::configureMailer();
            }
        } catch (\Throwable $e) {
            // Ignore during early bootstrap or when database is not yet ready
        }
    }
}
