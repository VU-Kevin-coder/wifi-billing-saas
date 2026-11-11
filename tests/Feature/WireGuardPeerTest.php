<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\WireGuardCommandRunner;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WireGuardPeerTest extends TestCase
{
    use RefreshDatabase;

    public function test_peer_generation_assigns_unique_tenant_subnet_and_encrypts_private_key(): void
    {
        config([
            'wireguard.enabled' => true,
            'wireguard.endpoint' => 'vpn.example.test',
            'wireguard.listen_port' => 51820,
        ]);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $router = $tenant->routers()->create(['name' => 'Router A']);
        $privateKey = base64_encode(str_repeat('p', 32));
        $publicKey = base64_encode(str_repeat('u', 32));
        $serverPublicKey = base64_encode(str_repeat('s', 32));
        $runner = Mockery::mock(WireGuardCommandRunner::class);
        $runner->shouldReceive('run')->andReturn(
            $serverPublicKey,
            $privateKey,
            $publicKey,
            '',
            '',
        );

        $peer = (new WireGuardService($runner))->createPeer($router, 'Router A');

        $this->assertSame('10.8.1.0/24', $tenant->fresh()->vpn_subnet);
        $this->assertSame('10.8.1.2', $peer->tunnel_ip);
        $this->assertSame($privateKey, $peer->private_key);
        $this->assertNotSame($privateKey, $peer->getRawOriginal('private_key'));
        $this->assertArrayNotHasKey('private_key', $peer->toArray());
    }

    public function test_router_configuration_is_delivered_once_then_private_key_is_erased(): void
    {
        config([
            'wireguard.enabled' => true,
            'wireguard.endpoint' => 'vpn.example.test',
            'wireguard.listen_port' => 51820,
        ]);
        $tenant = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $router = $tenant->routers()->create(['name' => 'Router B']);
        $privateKey = base64_encode(str_repeat('k', 32));
        $peer = VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Router B',
            'public_key' => base64_encode(str_repeat('b', 32)),
            'private_key' => $privateKey,
            'tunnel_ip' => '10.8.1.2',
            'enabled' => true,
        ]);
        $runner = Mockery::mock(WireGuardCommandRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->withArgs(fn (array $arguments): bool => $arguments === ['wg', 'show', 'wg0', 'public-key'])
            ->andReturn(base64_encode(str_repeat('s', 32)));
        $service = new WireGuardService($runner);

        $configuration = $service->routerConfiguration($peer);

        $this->assertStringContainsString('private-key="'.$privateKey.'"', $configuration);
        $this->assertStringContainsString('10.8.1.2/32', $configuration);
        $this->assertStringContainsString('endpoint-address="vpn.example.test"', $configuration);
        $this->assertNull($peer->fresh()->private_key);
        $this->assertNotNull($peer->fresh()->config_downloaded_at);
        $this->expectException(RuntimeException::class);
        $service->routerConfiguration($peer->fresh());
    }
}
