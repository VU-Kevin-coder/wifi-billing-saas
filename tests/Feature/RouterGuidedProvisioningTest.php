<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RouterConnectionService;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RouterGuidedProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guided_provisioning_requires_explicit_confirmation_and_a_connected_wireguard_peer(): void
    {
        $router = $this->makeConnectedRouter();

        $unconfirmed = app(RouterProvisioningService::class)->deployGuided($router, false);
        $this->assertFalse($unconfirmed['ok']);
        $this->assertStringContainsString('Confirm', $unconfirmed['message']);

        $router->onboarding()->update(['status' => 'awaiting_bootstrap']);
        $disconnected = app(RouterProvisioningService::class)->deployGuided($router->fresh(), true);
        $this->assertFalse($disconnected['ok']);
        $this->assertStringContainsString('Verify the router connection', $disconnected['message']);
    }

    public function test_guided_provisioning_enables_radius_and_uses_the_verified_tunnel_source(): void
    {
        config([
            'radius.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
            'tenancy.base_domain' => 'example.test',
        ]);
        $router = $this->makeConnectedRouter();
        $script = null;
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('executeOnboardingScript')
            ->once()
            ->withArgs(function (Router $target, string $source) use (&$script, $router): bool {
                $this->assertSame($router->id, $target->id);
                $script = $source;

                return true;
            });
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router, true);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('use-radius=yes radius-accounting=yes radius-interim-update=1m', $script);
        $this->assertStringContainsString('address="10.8.0.1" src-address="10.8.1.20"', $script);
        $this->assertStringContainsString('secret="'.str_repeat('a', 64).'"', $script);
        $this->assertStringContainsString('name="radius-nas-test"', $script);
        $this->assertStringNotContainsString('address="192.0.2.1"', $script);
    }

    private function makeConnectedRouter(): Router
    {
        $tenant = Tenant::create([
            'name' => 'Onboarding Test',
            'slug' => 'onboarding-test',
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Router',
            'status' => 'provisioning',
            'api_username' => 'billing_api',
            'api_password' => 'a-strong-router-password',
            'api_port' => 8728,
            'enable_hotspot' => true,
            'hotspot_config' => [
                'network' => '10.10.0.0/16',
                'gateway' => '10.10.0.1',
                'dns' => '1.1.1.1,8.8.8.8',
                'domain' => 'hotspot.example.test',
                'wan_interface' => 'ether1',
            ],
            'ethernet_ports' => ['ether2'],
        ]);
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'radius-nas-test',
            'radius_shared_secret' => str_repeat('a', 64),
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Test peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);
        RouterOnboarding::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', random_bytes(32)),
            'bootstrap_script' => '/system identity print',
            'bootstrap_command' => 'not-used',
            'status' => 'connected',
            'expires_at' => now()->addMinutes(30),
            'connected_at' => now(),
        ]);

        return $router->fresh(['vpnPeer', 'onboarding']);
    }
}
