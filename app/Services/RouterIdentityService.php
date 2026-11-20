<?php

namespace App\Services;

use App\Models\Router;
use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterIdentityService
{
    public function discover(Router $router): array
    {
        $router->loadMissing('vpnPeer');
        $peer = $router->vpnPeer;

        if (! $router->enrolled || ! $peer?->enabled
            || blank($router->api_username) || blank($router->api_password)) {
            throw new RouterTransportException('Router identity discovery requires enrollment, an enabled WireGuard peer, and API credentials.');
        }

        $host = $peer->tunnel_ip;
        $key = 'identity-router-'.$router->id;
        DynamicMikrotikRouterConfig::set($key, [
            'username' => $router->api_username,
            'password' => $router->api_password,
            ...RouterTransportConfig::forVerifiedWireGuardPeer(
                (string) $host,
                (int) ($router->api_port ?: 8729),
                $peer,
                $router->id,
                true,
            ),
            'timeout' => 5,
        ]);

        $client = MikroTik::router($key);
        $resources = $client->system()->getResources();
        $model = trim((string) (
            $resources['board-name']
            ?? $resources['platform']
            ?? ''
        ));

        $serial = $this->licenseSystemId($key);

        if ($model === '' || $serial === '') {
            throw new RuntimeException('Router identity discovery returned an incomplete model or serial number.');
        }

        return [
            'hardware_model' => $model,
            'serial_number' => $serial,
            'routeros_version' => (string) ($resources['version'] ?? ''),
            'identity_state' => $this->status($router, $model, $serial),
        ];
    }

    public function status(Router $router, ?string $model = null, ?string $serial = null): string
    {
        if (! $router->identity_verified_at) {
            return 'not_enrolled';
        }

        $model ??= $router->hardware_model;
        $serial ??= $router->serial_number;
        if (blank($model) || blank($serial)) {
            return 'not_enrolled';
        }

        return $this->matchesIdentity(
            (string) $router->hardware_model,
            (string) $router->serial_number,
            (string) $model,
            (string) $serial,
        ) ? 'verified' : 'mismatch';
    }

    public function matches(Router $router, array $identity): bool
    {
        if (! $router->identity_verified_at
            || blank($router->hardware_model)
            || blank($router->serial_number)
            || blank($identity['hardware_model'] ?? null)
            || blank($identity['serial_number'] ?? null)) {
            return false;
        }

        return $this->matchesIdentity(
            (string) $router->hardware_model,
            (string) $router->serial_number,
            (string) $identity['hardware_model'],
            (string) $identity['serial_number'],
        );
    }

    private function matchesIdentity(string $model, string $serial, string $detectedModel, string $detectedSerial): bool
    {
        return hash_equals(strtolower(trim($model)), strtolower(trim($detectedModel)))
            && hash_equals(strtoupper(trim($serial)), strtoupper(trim($detectedSerial)));
    }

    private function licenseSystemId(string $key): string
    {
        try {
            foreach (MikroTik::router($key)->diagnostics()->getRaw('/system/license/print') as $word) {
                if (str_starts_with($word, '=system-id=')) {
                    return trim(substr($word, strlen('=system-id=')));
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function optionalRawRows(string $key, string $command): array
    {
        try {
            return $this->rawRows($key, $command);
        } catch (\Throwable) {
            return [];
        }
    }

    private function rawRows(string $key, string $command): array
    {
        $response = MikroTik::router($key)->diagnostics()->getRaw($command);
        $rows = [];
        $row = [];

        foreach ($response as $word) {
            if (str_starts_with($word, '!trap') || str_starts_with($word, '!fatal')) {
                throw new RuntimeException("RouterOS rejected read-only command {$command}.");
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

        return $rows;
    }
}
