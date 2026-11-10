<?php

namespace App\Services;

use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\MikrotikManager;

class DynamicMikrotikRouterConfig
{
    /**
     * Register a router before selecting it through the MikroTik manager.
     */
    public static function set(string $key, array $router): void
    {
        config(["mikrotik.routers.{$key}" => $router]);

        if (! app()->resolved(MikrotikManager::class)) {
            return;
        }

        $manager = app(MikrotikManager::class);
        if (get_class($manager) !== MikrotikManager::class) {
            return;
        }

        app()->forgetInstance(MikrotikManager::class);
        MikroTik::clearResolvedInstance();
    }
}
