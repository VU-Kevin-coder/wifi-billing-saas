<?php

namespace App\Services;

use RuntimeException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\Services\HotspotManager;

class HotspotProfileSynchronizer
{
    public function sync(string $routerKey, HotspotManager $hotspot, array $settings): void
    {
        $name = $settings['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Router profile sync requires a non-empty profile name.');
        }

        $profile = [
            'name' => $name,
            'rate-limit' => (string) ($settings['rate-limit'] ?? ''),
            'shared-users' => max(1, (int) ($settings['shared-users'] ?? 1)),
        ];
        $existing = collect($hotspot->getProfiles())
            ->first(fn (array $item): bool => ($item['name'] ?? null) === $name);

        if (! $existing) {
            $hotspot->createProfile($profile);

            return;
        }

        if (! is_string($existing['.id'] ?? null) || $existing['.id'] === '') {
            throw new RuntimeException("Router profile '{$name}' did not include its RouterOS identifier.");
        }

        $client = MikroTik::getPool()->get($routerKey);
        if (! $client) {
            throw new RuntimeException('Router API client is unavailable while updating a HotSpot profile.');
        }

        $client->query('/ip/hotspot/user/profile/set', [
            '.id' => $existing['.id'],
            ...$profile,
        ]);
    }
}
