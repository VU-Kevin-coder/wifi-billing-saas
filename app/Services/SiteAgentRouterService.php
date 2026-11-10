<?php

namespace App\Services;

use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class SiteAgentRouterService
{
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
            'host' => $router['host'],
            'username' => $router['username'],
            'password' => $router['password'],
            'port' => (int) ($router['port'] ?? 8728),
            'timeout' => 5,
        ]);
        $hotspot = MikroTik::router($key)->hotspot();
        $username = $payload['username'] ?? null;
        if (! is_string($username) || $username === '') {
            throw new RuntimeException('Router command did not contain a valid voucher username.');
        }

        if ($operation === 'provision_user') {
            $data = array_filter([
                'name' => $username,
                'password' => $payload['password'] ?? $username,
                'profile' => $payload['profile'] ?? 'default',
                'limit-uptime' => $payload['limit_uptime'] ?? null,
                'shared-users' => max(1, (int) ($payload['shared_users'] ?? 1)),
                'rate-limit' => $payload['rate_limit'] ?? null,
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
