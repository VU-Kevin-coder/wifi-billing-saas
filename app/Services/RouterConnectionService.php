<?php

namespace App\Services;

use App\Models\Router;
use App\Models\VpnPeer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        if (config('site_agent.required')) {
            return [
                'ok' => false,
                'message' => 'Direct router connections are disabled. Use the paired site agent.',
            ];
        }

        $host = $this->hostForRouter($router);

        if (blank($host) || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'failure_type' => 'missing_configuration',
                'message' => 'Router API test is missing connection settings.',
                'diagnostic' => blank($host)
                    ? 'No reachable router address is configured. Enable a WireGuard peer or allow direct connections and set the router management address.'
                    : (blank($router->api_username)
                        ? 'The router API username is empty.'
                        : 'The router API password is empty.'),
            ];
        }

        try {
            $router->loadMissing('vpnPeer');
            $key = $router->vpnPeer?->enabled && $router->vpnPeer->tunnel_ip === $host
                ? $this->configureRouter($router, $host, $router->vpnPeer)
                : $this->configureRouter($router, $host);
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
            // Client exceptions may contain credentials or other private data.
            // Only disclose the fixed, non-secret transport validation messages.
            $safeTransportMessages = [
                'Router API port is invalid.',
                'Router API requires verified TLS; plaintext API is blocked.',
                'Router API-SSL requires a valid IPv4 address or DNS hostname for certificate identity verification.',
            ];
            $diagnostic = $exception instanceof RouterTransportException
                && in_array($exception->getMessage(), $safeTransportMessages, true)
                ? $exception->getMessage()
                : 'Connection or authentication failed.';

            Log::warning('MikroTik LAN demo connection failed', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
                'diagnostic' => $diagnostic,
            ]);

            return [
                'ok' => false,
                'message' => 'Router API test failed. '.$diagnostic,
                'diagnostic' => $diagnostic,
            ];
        }
    }

    public function testOnboardingWireGuard(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer || ! $peer->enabled || blank($router->api_username) || blank($router->api_password)) {
            return [
                'ok' => false,
                'message' => 'The router has not completed its WireGuard and API bootstrap.',
            ];
        }

        try {
            $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
            $resource = MikroTik::router($key)->system()->getResources();

            return [
                'ok' => filled($resource),
                'message' => filled($resource)
                    ? 'Router WireGuard and API connection verified.'
                    : 'Router API returned no system resource data.',
                'resource' => $resource,
            ];
        } catch (\Throwable $exception) {
            Log::notice('Router onboarding API verification failed.', [
                'router_id' => $router->getKey(),
                'exception' => $exception::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Waiting for the router API to become reachable through WireGuard.',
            ];
        }
    }

    public function onboardingInterfaces(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;
        if (! $peer || ! $peer->enabled) {
            throw new RouterTransportException('An enabled WireGuard peer is required.');
        }

        $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);

        try {
            return MikroTik::router($key)->interfaces()->getInterfaces();
        } catch (\Throwable $exception) {
            Log::notice('Router onboarding could not read interface inventory.', [
                'router_id' => $router->id,
                'exception' => $exception::class,
            ]);

            throw new RouterTransportException('Unable to read router interfaces through the verified WireGuard connection.');
        }
    }

    public function executeOnboardingScript(Router $router, string $source): void
    {
        $router->loadMissing(['vpnPeer', 'onboarding']);
        $peer = $router->vpnPeer;
        if (! $peer || ! $peer->enabled || $router->onboarding?->status !== 'connected') {
            throw new RouterTransportException('Only a connected, verified WireGuard onboarding session may configure a router.');
        }

        $key = $this->configureRouter($router, $peer->tunnel_ip, $peer);
        $scripts = MikroTik::router($key)->scripts();
        $name = 'wifi-billing-onboard-'.$router->id.'-'.strtolower(Str::random(8));
        $added = false;
        $runError = null;
        $cleanupError = null;

        try {
            $scripts->addScript($name, $source, 'read,write,api', 'Temporary Wi-Fi Billing onboarding');
            $added = true;
            $scripts->runScript($name);
        } catch (\Throwable $exception) {
            $runError = $exception;
        } finally {
            if ($added) {
                try {
                    $scripts->removeScript($name);
                } catch (\Throwable $exception) {
                    $cleanupError = $exception;
                }
            }
        }

        if ($cleanupError) {
            Log::critical('Temporary router onboarding script could not be removed.', [
                'router_id' => $router->id,
                'exception' => $cleanupError::class,
            ]);
            throw new RouterTransportException('Router provisioning ran but its temporary script could not be removed. Inspect the router before retrying.');
        }

        if ($runError) {
            Log::warning('Router onboarding script execution failed.', [
                'router_id' => $router->id,
                'exception' => $runError::class,
            ]);
            throw new RouterTransportException('Router provisioning failed. Check RouterOS logs before retrying.');
        }
    }

    public function liveStatus(Router $router): array
    {
        if (config('site_agent.required')) {
            if (! $router->telemetry_at) {
                return [
                    'ok' => false,
                    'message' => 'No router telemetry has been reported by the site agent yet.',
                    'cpu_load' => 0,
                    'memory_usage' => 0,
                    'uptime' => 'N/A',
                    'active_users' => null,
                ];
            }

            $fresh = $router->telemetry_at->greaterThanOrEqualTo(now()->subMinutes(2));

            return [
                'ok' => $fresh,
                'message' => $fresh
                    ? 'Router telemetry reported by the paired site agent '.$router->telemetry_at->diffForHumans().'.'
                    : 'Router telemetry is stale; last reported by the paired site agent '.$router->telemetry_at->diffForHumans().'.',
                'cpu_load' => $router->cpu_usage,
                'memory_usage' => $router->memory_usage,
                'uptime' => $router->uptime ?: 'N/A',
                'active_users' => $router->active_users,
            ];
        }

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
        if (config('site_agent.required')) {
            throw new RuntimeException('Direct router connections are disabled. Use the paired site agent.');
        }

        $host = $this->hostForRouter($router);

        if (blank($host) || blank($router->api_username) || blank($router->api_password)) {
            throw new RuntimeException("Router {$router->getKey()} is missing API connection details.");
        }

        $key = $this->configureRouter($router, $host);

        try {
            $hotspot = MikroTik::router($key)->hotspot();
            $isActive = collect($hotspot->getActiveHosts())
                ->contains(fn (array $host): bool => ($host['user'] ?? null) === $voucherCode);

            if (! $isActive) {
                return false;
            }

            $hotspot->kickHost($voucherCode);

            return true;
        } catch (\Throwable $exception) {
            // Client exceptions may contain credentials; do not retain them as a cause.
            throw new RouterTransportException('Unable to disconnect the router session.');
        }
    }

    private function configureRouter(Router $router, ?string $host = null, ?VpnPeer $wireGuardPeer = null): string
    {
        $host ??= $this->hostForRouter($router);
        if (blank($host)) {
            throw new RuntimeException("Router {$router->getKey()} has no permitted connection address.");
        }

        $key = 'lan-demo-router-'.$router->getKey();
        DynamicMikrotikRouterConfig::set($key, [
            'username' => $router->api_username,
            'password' => $router->api_password,
            ...($wireGuardPeer
                ? RouterTransportConfig::forVerifiedWireGuardPeer($host, (int) ($router->api_port ?: 8728), $wireGuardPeer, $router->getKey())
                : RouterTransportConfig::forRouter($host, (int) ($router->api_port ?: 8728), $router->getKey())),
            'timeout' => 5,
        ]);

        return $key;
    }

    private function hostForRouter(Router $router): ?string
    {
        $router->loadMissing('vpnPeer');

        return $router->vpnPeer?->enabled
            ? $router->vpnPeer->tunnel_ip
            : (config('site_agent.allow_direct_router_connection') ? $router->management_ip : null);
    }
}
