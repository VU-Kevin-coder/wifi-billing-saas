<?php

namespace App\Services;

use RuntimeException;
use ZillEAli\MikrotikLaravel\Connections\RouterosClient;
use ZillEAli\MikrotikLaravel\MikrotikManager;

class RouterMikrotikManager extends MikrotikManager
{
    protected function getClient(): RouterosClient
    {
        $name = $this->currentRouterName();
        $config = $this->getRouterConfig($name);
        $useSsl = $config['ssl'] ?? $this->config['ssl'] ?? false;
        if (! $useSsl || ($config['verify_peer_name'] ?? true) !== false) {
            return parent::getClient();
        }

        if (($config['verify_peer'] ?? false) !== true || blank($config['ca_cert_path'] ?? null)) {
            throw new RuntimeException('Disabling router API hostname verification requires certificate peer verification and a trusted CA.');
        }

        $name = $this->resolveAndResetRouter();
        if ($this->pool->isAlive($name)) {
            return $this->pool->get($name);
        }

        $config = $this->getRouterConfig($name);
        $client = new RouterosClientSSLWithPeerName(
            host: $config['host'],
            port: $config['port'] ?? 8729,
            username: $config['username'] ?? 'admin',
            password: $config['password'] ?? '',
            timeout: $config['timeout'] ?? 10,
            verifyPeer: true,
            caCertPath: $config['ca_cert_path'],
            verifyPeerName: false,
        );
        $client->configure([
            'socket_timeout' => $this->config['socket_timeout'] ?? 30,
            'socket_blocking' => $this->config['socket_blocking'] ?? true,
            'throw_timeout_exception' => $this->config['throw_timeout_exception'] ?? true,
        ]);

        $this->connectWithRetry(
            $client,
            $this->config['retry_attempts'] ?? 1,
            $this->config['retry_delay'] ?? 1000,
            $name,
            $config,
        );
        $this->pool->add($name, $client);

        return $client;
    }
}
