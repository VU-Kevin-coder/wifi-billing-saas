<?php

namespace App\Services;

use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class SiteAgentRouterService
{
    public function __construct(private HotspotProfileSynchronizer $profiles) {}

    public function execute(int $routerId, string $operation, array $payload): void
    {
        $router = config('site_agent.routers.'.$routerId);
        if (! is_array($router)
            || blank($router['host'] ?? null)
            || blank($router['username'] ?? null)
            || blank($router['password'] ?? null)) {
            throw new RuntimeException("Router {$routerId} is not configured in the site-local router map.");
        }

        $key = 'site-agent-router-'.$routerId;
        DynamicMikrotikRouterConfig::set($key, [
            'username' => $router['username'],
            'password' => $router['password'],
            ...RouterTransportConfig::forRouter(
                (string) $router['host'],
                (int) ($router['port'] ?? 8728),
                $routerId,
            ),
            'timeout' => 5,
        ]);

        if ($operation === 'test_connection') {
            $resources = MikroTik::router($key)->system()->getResources();
            if (! is_array($resources) || $resources === []) {
                throw new RuntimeException('Router API returned no system resource data.');
            }

            return;
        }

        $hotspot = MikroTik::router($key)->hotspot();

        if ($operation === 'sync_profile') {
            $profileSettings = $payload['profiles'] ?? [];
            if (! is_array($profileSettings)) {
                throw new RuntimeException('Profile sync command did not contain a valid profile list.');
            }

            foreach ($profileSettings as $settings) {
                if (! is_array($settings)) {
                    throw new RuntimeException('Profile sync command contained an invalid profile.');
                }

                $this->profiles->sync($key, $hotspot, $settings);
            }

            return;
        }

        $username = $payload['username'] ?? null;
        if (! is_string($username) || $username === '') {
            throw new RuntimeException('Router command did not contain a valid voucher username.');
        }

        if ($operation === 'provision_user') {
            if (is_array($payload['profile_settings'] ?? null)) {
                $this->profiles->sync($key, $hotspot, $payload['profile_settings']);
            }

            $data = array_filter([
                'name' => $username,
                'password' => $payload['password'] ?? $username,
                'profile' => $payload['profile'] ?? 'default',
                'limit-uptime' => $payload['limit_uptime'] ?? null,
                'limit-bytes-total' => $payload['limit_bytes_total'] ?? null,
                'comment' => 'Provisioned by Wi-Fi Billing site agent',
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($hotspot->getUser($username)) {
                $hotspot->updateUser($username, $data);
            } else {
                $hotspot->createUser($data);
            }

            return;
        }

        if ($operation === 'expire_user') {
            if ($hotspot->getUser($username)) {
                $hotspot->disableUser($username);
            }

            $active = collect($hotspot->getActiveHosts())
                ->contains(fn (array $host): bool => ($host['user'] ?? null) === $username);
            if ($active) {
                $hotspot->kickHost($username);
            }

            return;
        }

        throw new RuntimeException("Unsupported site-agent operation '{$operation}'.");
    }
}
