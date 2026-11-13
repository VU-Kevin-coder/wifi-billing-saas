<?php

namespace App\Services;

use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class SiteAgentRouterService
{
    public function __construct(private HotspotProfileSynchronizer $profiles) {}

    public function execute(int $routerId, string $operation, array $payload): ?array
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

            return null;
        }

        if ($operation === 'telemetry') {
            $client = MikroTik::router($key);
            $resources = $client->system()->getResources();
            if (! is_array($resources) || $resources === []) {
                throw new RuntimeException('Router API returned no system resource data.');
            }

            $totalMemory = $resources['total-memory'] ?? null;
            $freeMemory = $resources['free-memory'] ?? null;
            $cpuLoad = $resources['cpu-load'] ?? null;
            $uptime = $resources['uptime'] ?? null;
            if (! is_numeric($totalMemory) || (float) $totalMemory <= 0
                || ! is_numeric($freeMemory) || ! is_numeric($cpuLoad)
                || ! is_string($uptime) || $uptime === '') {
                throw new RuntimeException('Router API returned incomplete system telemetry.');
            }

            $activeHosts = $client->hotspot()->getActiveHosts();
            if (! is_array($activeHosts)) {
                throw new RuntimeException('Router API returned invalid active hotspot users.');
            }

            return [
                'cpu_usage' => (int) min(100, max(0, (int) $cpuLoad)),
                'memory_usage' => (int) min(100, max(0, round((1 - ((float) $freeMemory / (float) $totalMemory)) * 100))),
                'active_users' => count($activeHosts),
                'uptime' => $uptime,
            ];
        }

        $hotspot = MikroTik::router($key)->hotspot();

        if ($operation === 'ban_mac') {
            $username = $payload['username'] ?? null;
            if (! is_string($username) || $username === '') {
                throw new RuntimeException('Device ban command did not contain a valid voucher username.');
            }

            $matchingHosts = collect($hotspot->getActiveHosts())
                ->filter(fn (array $host): bool => ($host['user'] ?? null) === $username)
                ->values();
            $macAddresses = $matchingHosts
                ->pluck('mac-address')
                ->filter(fn (mixed $mac): bool => is_string($mac) && preg_match('/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac))
                ->map(fn (string $mac): string => strtoupper($mac))
                ->unique()
                ->values();

            if ($macAddresses->count() !== 1) {
                throw new RuntimeException($macAddresses->isEmpty()
                    ? 'Router did not report an active device MAC for this voucher.'
                    : 'Router reports multiple devices for this voucher; no MAC was blocked.');
            }

            $macAddress = $macAddresses->first();
            $host = $matchingHosts->first(
                fn (array $item): bool => strtoupper((string) ($item['mac-address'] ?? '')) === $macAddress
            );
            $hostId = $host['.id'] ?? null;
            if (! is_string($hostId) || $hostId === '') {
                throw new RuntimeException('Router did not return an active-session identifier for the reported device.');
            }

            $bindings = collect($this->queryRouter($key, '/ip/hotspot/ip-binding/print'))
                ->filter(fn (array $binding): bool => strtoupper((string) ($binding['mac-address'] ?? '')) === $macAddress);
            $blockedBinding = collect($bindings)->first(
                fn (array $binding): bool => ($binding['type'] ?? null) === 'blocked'
            );

            if ($blockedBinding) {
                $bindingId = $blockedBinding['.id'] ?? null;
                if (! is_string($bindingId) || $bindingId === '') {
                    throw new RuntimeException('Router did not return the blocked binding identifier.');
                }
            } else {
                $existingBinding = collect($bindings)->first();
                $bindingId = $existingBinding['.id'] ?? null;
                if (is_string($bindingId) && $bindingId !== '') {
                    $this->queryRouter($key, '/ip/hotspot/ip-binding/set', [
                        '.id' => $bindingId,
                        'type' => 'blocked',
                    ]);
                } else {
                    $this->queryRouter($key, '/ip/hotspot/ip-binding/add', [
                        'mac-address' => $macAddress,
                        'type' => 'blocked',
                        'comment' => 'Blocked by Wi-Fi Billing operator',
                    ]);
                }
            }

            $this->queryRouter($key, '/ip/hotspot/active/remove', ['.id' => $hostId]);

            return ['mac_address' => $macAddress];
        }

        if ($operation === 'unban_mac') {
            $macAddress = $payload['mac_address'] ?? null;
            if (! is_string($macAddress)
                || ! preg_match('/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $macAddress)) {
                throw new RuntimeException('Device unban command did not contain a valid MAC address.');
            }

            $bindings = collect($this->queryRouter($key, '/ip/hotspot/ip-binding/print'))
                ->filter(fn (array $binding): bool => strtoupper((string) ($binding['mac-address'] ?? '')) === strtoupper($macAddress));
            foreach ($bindings as $binding) {
                if (($binding['type'] ?? null) !== 'blocked') {
                    continue;
                }

                $bindingId = $binding['.id'] ?? null;
                if (! is_string($bindingId) || $bindingId === '') {
                    throw new RuntimeException('Router did not return the blocked binding identifier.');
                }

                $this->queryRouter($key, '/ip/hotspot/ip-binding/remove', ['.id' => $bindingId]);
            }

            return null;
        }

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

            return null;
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

            return null;
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

            return null;
        }

        throw new RuntimeException("Unsupported site-agent operation '{$operation}'.");
    }

    private function queryRouter(string $key, string $command, array $parameters = []): array
    {
        $response = MikroTik::router($key)->diagnostics()->getRaw($command, $parameters);
        $rows = [];
        $row = [];

        foreach ($response as $word) {
            if (str_starts_with($word, '!trap') || str_starts_with($word, '!fatal')) {
                throw new RuntimeException("RouterOS rejected command {$command}.");
            }

            if ($word === '!re') {
                if ($row !== []) {
                    $rows[] = $row;
                    $row = [];
                }

                continue;
            }

            if ($word === '' || $word === '!done') {
                if ($row !== []) {
                    $rows[] = $row;
                    $row = [];
                }

                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                $row[$parts[0]] = $parts[1] ?? '';
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }
}
