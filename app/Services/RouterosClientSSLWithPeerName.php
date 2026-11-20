<?php

namespace App\Services;

use ZillEAli\MikrotikLaravel\Connections\RouterosClientSSL;

class RouterosClientSSLWithPeerName extends RouterosClientSSL
{
    private bool $verifyPeerName;

    public function __construct(
        string $host,
        int $port = 8729,
        string $username = 'admin',
        string $password = '',
        int $timeout = 10,
        bool $verifyPeer = false,
        ?string $caCertPath = null,
        bool $verifyPeerName = true,
    ) {
        $this->verifyPeerName = $verifyPeerName;
        parent::__construct($host, $port, $username, $password, $timeout, $verifyPeer, $caCertPath);
    }

    protected function buildSslContext(bool $verifyPeer, ?string $caCertPath): array
    {
        $ssl = [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $this->verifyPeerName,
            'allow_self_signed' => ! $verifyPeer,
        ];

        if ($verifyPeer && $caCertPath !== null) {
            $ssl['cafile'] = $caCertPath;
        }

        return ['ssl' => $ssl];
    }
}
