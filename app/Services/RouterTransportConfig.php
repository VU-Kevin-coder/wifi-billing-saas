<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RouterTransportConfig
{
    public static function forRouter(string $host, int $port, int|string $routerId): array
    {
        $ssl = $port === 8729;
        $verifyPeer = (bool) config('site_agent.router_ssl_verify_peer');

        if (! $ssl && config('app.env') === 'production') {
            Log::warning('MikroTik router connection is using plaintext API.', [
                'router_id' => $routerId,
                'port' => $port,
            ]);
        }
        if ($ssl && config('app.env') === 'production' && ! $verifyPeer) {
            Log::critical('MikroTik API-SSL certificate verification is disabled in production.', [
                'router_id' => $routerId,
            ]);
        }

        return [
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
            'verify_peer' => $verifyPeer,
            'ca_cert_path' => config('site_agent.router_ca_cert_path'),
        ];
    }
}
