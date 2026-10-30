<?php

namespace App\Providers;

use App\Services\Tenancy\TenantContext;
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
        //
    }
}