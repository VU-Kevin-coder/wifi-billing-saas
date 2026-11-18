<?php

namespace App\Services;

use App\Models\Router;
use RuntimeException;
use ZillEAli\MikrotikLaravel\Exceptions\ApiException;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterIdentityService
{
    public function inspect(string $routerKey, ?array $resources = null): array
    {
        $client = MikroTik::router($routerKey);
        $resources ??= $client->system()->getResources();
        try {
            $serialNumber = $this->readValue(
                $client->diagnostics()->getRaw('/system/routerboard/print'),
                'serial-number',
            );
        } catch (ApiException) {
            $serialNumber = '';
        }

        if ($serialNumber === '') {
            $serialNumber = $this->readValue(
                $client->diagnostics()->getRaw('/system/license/print'),
                'system-id',
            );
        }

        return [
            'hardware_model' => trim((string) ($resources['board-name'] ?? '')),
            'serial_number' => $serialNumber,
            'routeros_version' => trim((string) ($resources['version'] ?? '')),
            'system_identity' => trim((string) $client->system()->getIdentity()),
        ];
    }

    public function status(Router $router, array $identity): string
    {
        if (! $router->identity_verified_at) {
            return 'unverified';
        }

        return $this->statusForExpected($identity, [
            'hardware_model' => $router->hardware_model,
            'serial_number' => $router->serial_number,
            'verified' => true,
        ]);
    }

    public function statusForExpected(array $identity, ?array $expected): string
    {
        if (! is_array($expected) || ! ($expected['verified'] ?? false)) {
            return 'unverified';
        }

        return $this->matches($identity, $expected) ? 'verified' : 'mismatch';
    }

    public function assertMatchesRouter(Router $router, string $routerKey): array
    {
        $identity = $this->inspect($routerKey);
        $this->assertMatches($identity, [
            'hardware_model' => $router->hardware_model,
            'serial_number' => $router->serial_number,
            'verified' => (bool) $router->identity_verified_at,
        ]);

        return $identity;
    }

    public function assertMatches(array $identity, ?array $expected): void
    {
        if (! is_array($expected)
            || ! ($expected['verified'] ?? false)
            || ! $this->matches($identity, $expected)) {
            throw new RuntimeException('Router identity is not enrolled or does not match; the write was blocked.');
        }
    }

    public function matches(array $identity, array $expected): bool
    {
        $observedModel = $this->normalize($identity['hardware_model'] ?? null);
        $expectedModel = $this->normalize($expected['hardware_model'] ?? null);
        $observedSerial = $this->normalize($identity['serial_number'] ?? null);
        $expectedSerial = $this->normalize($expected['serial_number'] ?? null);

        return $observedModel !== ''
            && $observedSerial !== ''
            && $expectedModel !== ''
            && $expectedSerial !== ''
            && hash_equals($expectedModel, $observedModel)
            && hash_equals($expectedSerial, $observedSerial);
    }

    private function normalize(mixed $value): string
    {
        return is_string($value) ? mb_strtoupper(trim($value), 'UTF-8') : '';
    }

    private function readValue(array $words, string $key): string
    {
        foreach ($words as $word) {
            if (! is_string($word) || ! str_starts_with($word, '='.$key.'=')) {
                continue;
            }

            return trim(substr($word, strlen($key) + 2));
        }

        return '';
    }
}
