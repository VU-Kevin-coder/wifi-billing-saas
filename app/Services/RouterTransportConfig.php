<?php

namespace App\Services;

use App\Models\VpnPeer;
use Illuminate\Support\Facades\Log;

class RouterTransportConfig
{
    public static function forRouter(
        string $host,
        int $port,
        int|string $routerId,
        bool $requireRouterCertificate = false,
    ): array {
        if ($port < 1 || $port > 65535) {
            throw new RouterTransportException('Router API port is invalid.');
        }

        // Reserve 8728 for the conventional plaintext RouterOS API. This
        // explicit opt-in is for tests only, not a deployment setting. Both
        // Laravel's actual and configured environments must say testing.
        $plaintext = $port === 8728;
        if ($plaintext && $requireRouterCertificate) {
            throw new RouterTransportException('Enrolled routers must use verified API-SSL on port 8729.');
        }
        if ($plaintext && ! (app()->environment('testing')
            && config('app.env') === 'testing'
            && config('router.testing_allow_plaintext_api') === true)) {
            Log::warning('MikroTik router plaintext API blocked.', [
                'router_id' => $routerId,
                'port' => $port,
            ]);
            throw new RouterTransportException('Router API requires verified TLS; plaintext API is blocked.');
        }

        // The client selects TLS using the ssl option independently of port.
        // 8729 and every other valid non-8728 port use verified TLS, including
        // custom ports; a private address or VPN does not imply encryption.
        if (! $plaintext && ! self::isValidPeerHost($host)) {
            throw new RouterTransportException('Router API-SSL requires a valid IPv4 address or DNS hostname for certificate identity verification.');
        }

        $routerCaPath = $requireRouterCertificate
            ? RouterApiCertificateTrustService::pathForRouter($routerId)
            : null;
        if ($requireRouterCertificate && $routerCaPath === null) {
            throw new RouterTransportException('The enrolled router API certificate is unavailable; re-enrollment is required.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'ssl' => ! $plaintext,
            // Router tokens establish enrollment identity; the stored CA still
            // authenticates the peer when hostname verification is disabled.
            'verify_peer' => true,
            'verify_peer_name' => ! $requireRouterCertificate,
            'ca_cert_path' => $routerCaPath ?? config('router.api_ca_cert_path'),
        ];
    }

    /**
     * The RouterOS plaintext API is acceptable only inside the authenticated,
     * encrypted WireGuard tunnel and only for that peer's assigned tunnel IP.
     */
    public static function forVerifiedWireGuardPeer(
        string $host,
        int $port,
        VpnPeer $peer,
        int|string $routerId,
        bool $requireRouterCertificate = false,
    ): array {
        if (! $peer->enabled
            || filter_var($peer->tunnel_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! hash_equals($peer->tunnel_ip, $host)) {
            throw new RouterTransportException('Router API host is not bound to its enabled WireGuard peer.');
        }

        if ($port === 8728) {
            if ($requireRouterCertificate) {
                throw new RouterTransportException('Enrolled routers must use verified API-SSL on port 8729.');
            }

            return [
                'host' => $host,
                'port' => $port,
                'ssl' => false,
                'verify_peer' => false,
                'ca_cert_path' => null,
            ];
        }

        return self::forRouter($host, $port, $routerId, $requireRouterCertificate);
    }

    private static function isValidPeerHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        // IPv6 is deliberately rejected: the upstream client's ssl:// address
        // interpolation does not bracket literals. Do not guess a peer name.
        if (filter_var($host, FILTER_VALIDATE_IP) || strlen($host) > 253) {
            return false;
        }

        return (bool) preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/D', $host);
    }
}
