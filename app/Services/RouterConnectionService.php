<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

/**
 * Read-only MikroTik API connection seam for a single saved router.
 *
 * Router records are the source of truth: their API password is encrypted by
 * the Router model, unlike the legacy tenant-level connection fields.
 */
class RouterConnectionService
{
    public function test(Router $router): array
    {
        if (blank($router->management_ip) || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'Add a host/IP, API username and API password before testing.',
            ];
        }

        $key = 'lan-demo-router-' . $router->getKey();

        config([
            "mikrotik.routers.{$key}" => [
                'host' => $router->management_ip,
                'username' => $router->api_username,
                'password' => $router->api_password,
                'port' => (int) ($router->api_port ?: 8728),
                'timeout' => 5,
            ],
        ]);

        try {
            // This is deliberately read-only: it proves API reachability
            // without changing hotspot, users, profiles or firewall rules.
            $resource = MikroTik::router($key)->system()->getResources();

            return [
                'ok' => filled($resource),
                'message' => filled($resource)
                    ? 'Router API responded successfully.'
                    : 'Router API returned no system resource data.',
                'resource' => $resource,
            ];
        } catch (\Throwable $exception) {
            Log::warning('MikroTik LAN demo connection failed', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Router did not respond. Check that this Laravel process is on the same reachable network, API is enabled, and the port/user are correct.',
            ];
        }
    }
}
