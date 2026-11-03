<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

/**
 * MikroTik API operations for a single saved router.
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

        $key = $this->configureRouter($router);

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
                'host' => $router->management_ip,
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Router did not respond. Check that this Laravel process is on the same reachable network, API is enabled, and the port/user are correct.',
            ];
        }
    }

    public function liveStatus(Router $router): array
    {
        $status = [
            'ok' => false,
            'message' => 'Live router metrics are unavailable.',
            'cpu_load' => 0,
            'memory_usage' => 0,
            'uptime' => 'N/A',
            'active_users' => 0,
        ];

        $connection = $this->test($router);
        if (! $connection['ok'] || ! is_array($connection['resource'] ?? null)) {
            $status['message'] = $connection['message'];

            return $status;
        }

        $resource = $connection['resource'];
        $totalMemory = is_numeric($resource['total-memory'] ?? null)
            ? (float) $resource['total-memory']
            : 0;
        $freeMemory = is_numeric($resource['free-memory'] ?? null)
            ? (float) $resource['free-memory']
            : 0;
        $memoryUsage = $totalMemory > 0
            ? round((1 - ($freeMemory / $totalMemory)) * 100)
            : 0;

        $status = [
            'ok' => true,
            'message' => $connection['message'],
            'cpu_load' => is_numeric($resource['cpu-load'] ?? null)
                ? (int) $resource['cpu-load']
                : 0,
            'memory_usage' => (int) min(100, max(0, $memoryUsage)),
            'uptime' => filled($resource['uptime'] ?? null) ? $resource['uptime'] : 'N/A',
            'active_users' => null,
        ];

        try {
            $key = $this->configureRouter($router);
            $status['active_users'] = count(MikroTik::router($key)->hotspot()->getActiveHosts());
        } catch (\Throwable $exception) {
            Log::warning('Unable to fetch active hotspot users from MikroTik router', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
            ]);
        }

        return $status;
    }

    public function disconnectUser(Router $router, string $voucherCode): bool
    {
        if (blank($router->management_ip) || blank($router->api_username) || blank($router->api_password)) {
            throw new RuntimeException("Router {$router->getKey()} is missing API connection details.");
        }

        $key = $this->configureRouter($router);

        $hotspot = MikroTik::router($key)->hotspot();
        $isActive = collect($hotspot->getActiveHosts())
            ->contains(fn (array $host): bool => ($host['user'] ?? null) === $voucherCode);

        if (! $isActive) {
            return false;
        }

        $hotspot->kickHost($voucherCode);

        return true;
    }

    private function configureRouter(Router $router): string
    {
        $key = 'lan-demo-router-'.$router->getKey();

        config([
            "mikrotik.routers.{$key}" => [
                'host' => $router->management_ip,
                'username' => $router->api_username,
                'password' => $router->api_password,
                'port' => (int) ($router->api_port ?: 8728),
                'timeout' => 5,
            ],
        ]);

        return $key;
    }
}