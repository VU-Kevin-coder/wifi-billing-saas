<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\VpnPeer;
use App\Services\RouterConnectionService;
use App\Services\RouterTransportConfig;
use App\Services\RouterTransportException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterTransportConfigTest extends TestCase
{
    public function test_router_api_uses_verified_tls_for_default_and_custom_ports(): void
    {
        config(['router.api_ca_cert_path' => '/synthetic/ca.pem']);
        foreach ([8729, 12345] as $port) {
            $this->assertSame([
                'host' => 'router.example.test',
                'port' => $port,
                'ssl' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'ca_cert_path' => '/synthetic/ca.pem',
            ], RouterTransportConfig::forRouter('router.example.test', $port, 17));
        }
    }

    public function test_plaintext_transport_is_blocked_outside_explicit_test_opt_in(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'MikroTik router plaintext API blocked.',
            ['router_id' => 17, 'port' => 8728],
        );
        $this->expectException(RouterTransportException::class);
        $this->expectExceptionMessage('plaintext API is blocked');

        RouterTransportConfig::forRouter('router.example.test', 8728, 17);
    }

    public function test_wireguard_peer_binds_api_host_and_keeps_tls_peer_verification(): void
    {
        config(['router.api_ca_cert_path' => '/synthetic/ca.pem']);
        $peer = (new VpnPeer)->forceFill(['enabled' => true, 'tunnel_ip' => '10.8.1.12']);

        $config = RouterTransportConfig::forVerifiedWireGuardPeer(
            '10.8.1.12',
            8729,
            $peer,
            17,
        );

        $this->assertSame('10.8.1.12', $config['host']);
        $this->assertTrue($config['ssl']);
        $this->assertTrue($config['verify_peer']);
        $this->assertTrue($config['verify_peer_name']);
        $this->assertSame('/synthetic/ca.pem', $config['ca_cert_path']);

        $this->expectException(RouterTransportException::class);
        RouterTransportConfig::forVerifiedWireGuardPeer('192.0.2.1', 8729, $peer, 17);
    }

    public function test_router_management_ip_cannot_be_used_as_a_direct_api_fallback(): void
    {
        MikroTik::shouldReceive('router')->never();
        $router = new Router([
            'management_ip' => '192.0.2.1',
            'api_port' => 8729,
            'api_username' => 'synthetic-user',
            'api_password' => 'synthetic-secret',
        ]);
        $router->id = 17;
        $router->setRelation('vpnPeer', null);

        $result = app(RouterConnectionService::class)->test($router);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_configuration', $result['failure_type']);
        $this->assertSame('No enabled WireGuard tunnel address is configured for this router.', $result['diagnostic']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($result));
    }
}
