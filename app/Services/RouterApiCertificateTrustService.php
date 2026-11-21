<?php

namespace App\Services;

use App\Models\Router;
use RuntimeException;

class RouterApiCertificateTrustService
{
    public function store(Router $router, string $certificate): void
    {
        $certificate = $this->validatedCertificate($router, $certificate);
        $router->forceFill(['api_ca_certificate' => $certificate]);
        $router->saveOrFail();
    }

    public static function pathForRouter(int|string $routerId): ?string
    {
        if (! ctype_digit((string) $routerId)) {
            throw new RuntimeException('Router identity is invalid.');
        }

        $router = Router::withoutGlobalScopes()->find($routerId);
        if (! $router) {
            return null;
        }

        $certificate = $router->api_ca_certificate;
        if (! is_string($certificate) || $certificate === '') {
            $legacyPath = storage_path('app/router-ca/router-'.$routerId.'.pem');
            if (! is_file($legacyPath)) {
                return null;
            }

            $router->loadMissing('vpnPeer');
            app(self::class)->store($router, (string) file_get_contents($legacyPath));
            $certificate = $router->api_ca_certificate;
        }

        if (! is_string($certificate) || $certificate === '') {
            return null;
        }

        $router->loadMissing('vpnPeer');
        $directory = storage_path('framework/cache/router-ca');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The router API trust cache could not be created.');
        }

        $path = $directory.'/router-'.$routerId.'-'.hash('sha256', $certificate).'.pem';
        if (! is_file($path)) {
            $certificate = app(self::class)->validatedCertificate($router, $certificate);
            $temporaryPath = tempnam($directory, 'router-ca-');
            if ($temporaryPath === false) {
                throw new RuntimeException('A temporary router API trust file could not be created.');
            }

            try {
                if (file_put_contents($temporaryPath, $certificate.PHP_EOL, LOCK_EX) === false
                    || ! chmod($temporaryPath, 0600)) {
                    throw new RuntimeException('The router API trust certificate could not be cached.');
                }

                if (! is_file($path) && ! rename($temporaryPath, $path)) {
                    throw new RuntimeException('The router API trust certificate could not be cached.');
                }
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        }

        return $path;
    }

    private function validatedCertificate(Router $router, string $certificate): string
    {
        $certificate = trim($certificate);
        if (strlen($certificate) > 16384
            || ! str_starts_with($certificate, '-----BEGIN CERTIFICATE-----')
            || ! str_ends_with($certificate, '-----END CERTIFICATE-----')) {
            throw new RuntimeException('The router API certificate is invalid.');
        }

        $parsedCertificate = @openssl_x509_read($certificate);
        if ($parsedCertificate === false) {
            throw new RuntimeException('The router API certificate is invalid.');
        }

        $details = openssl_x509_parse($parsedCertificate);
        $publicKey = openssl_pkey_get_public($parsedCertificate);
        $tunnelIp = $router->vpnPeer?->tunnel_ip;
        $extensions = $details['extensions'] ?? [];
        $san = (string) ($extensions['subjectAltName'] ?? '');
        $constraints = (string) ($extensions['basicConstraints'] ?? '');

        if (! $publicKey
            || openssl_x509_verify($parsedCertificate, $publicKey) !== 1
            || ! preg_match('/(?:^|,\s*)CA:TRUE(?:,|$)/', $constraints)
            || ! is_string($tunnelIp)
            || ! preg_match('/(?:^|,\s*)IP Address:'.preg_quote($tunnelIp, '/').'(?:,|$)/', $san)
            || ! is_int($details['validFrom_time_t'] ?? null)
            || ! is_int($details['validTo_time_t'] ?? null)
            || $details['validFrom_time_t'] > time()
            || $details['validTo_time_t'] <= time()) {
            throw new RuntimeException('The router API certificate does not match its enrolled WireGuard identity.');
        }

        return $certificate;
    }
}
