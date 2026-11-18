<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RouterTransportConfig
{
    public static function forRouter(string $host, int $port, int|string $routerId): array
    {
        if ($port < 1 || $port > 65535) {
            throw new RouterTransportException('Router API port is invalid.');
        }

        // Reserve 8728 for the conventional plaintext RouterOS API. This
        // explicit opt-in is for tests only, not a deployment setting. Both
        // Laravel's actual and configured environments must say testing.
        $plaintext = $port === 8728;
        if ($plaintext && ! (app()->environment('testing')
            && config('app.env') === 'testing'
            && config('site_agent.testing_allow_plaintext_router_api') === true)) {
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

        return [
            'host' => $host,
            'port' => $port,
            'ssl' => ! $plaintext,
            // The library verifies both the certificate chain and the host name
            // when verify_peer is true. The original host is passed unchanged,
            // so DNS resolution never substitutes an IP for the peer identity.
            'verify_peer' => true,
            'ca_cert_path' => config('site_agent.router_ca_cert_path'),
        ];
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
