<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPeer;
use App\Services\RadiusRuntimeHealthCheck;
use App\Services\RouterConnectionService;
use App\Services\RouterProvisioningService;
use App\Services\RouterTransportException;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
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

    public function test_guided_provisioning_requires_native_api_ssl_port(): void
    {
        $router = $this->makeConnectedRouter();
        $router->forceFill(['api_port' => 8728])->saveOrFail();

        $result = app(RouterProvisioningService::class)->deployGuided($router->fresh(), true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API-SSL connection on port 8729', $result['message']);
    }

    public function test_guided_provisioning_enables_radius_and_uses_the_verified_tunnel_source(): void
    {
        config([
            'radius.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
            'tenancy.base_domain' => 'example.test',
        ]);
        $router = $this->makeConnectedRouter();
        $this->mockOperationalRadius();
        $script = null;
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($this->safeRouterState());
        $connection->shouldReceive('createProvisioningBackup')->once()->ordered()->andReturn('wifi-billing-pre-test.backup');
        $connection->shouldReceive('executeOnboardingScript')
            ->once()
            ->ordered()
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

    public function test_pppoe_only_provisioning_uses_a_separate_pap_radius_service(): void
    {
        config([
            'radius.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
            'tenancy.base_domain' => 'example.test',
        ]);
        $router = $this->makeConnectedRouter();
        $this->mockOperationalRadius();
        $router->forceFill([
            'hardware_model' => 'RB4011',
            'enable_hotspot' => false,
            'enable_pppoe' => true,
            'pppoe_config' => [
                'interface' => 'ether3',
                'vlan_id' => 101,
                'network' => '10.20.0.0/16',
                'gateway' => '10.20.0.1',
                'dns' => '1.1.1.1,8.8.8.8',
            ],
            'ethernet_ports' => [],
        ])->saveOrFail();
        $script = null;
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($this->safeRouterState());
        $connection->shouldReceive('createProvisioningBackup')->once()->ordered()->andReturn('wifi-billing-pre-test.backup');
        $connection->shouldReceive('executeOnboardingScript')
            ->once()
            ->ordered()
            ->withArgs(function (Router $target, string $source) use (&$script, $router): bool {
                $this->assertSame($router->id, $target->id);
                $script = $source;

                return true;
            });
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router->fresh(), true);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('name="pppoe-vlan-'.$router->id.'" interface="ether3" vlan-id=101', $script);
        $this->assertStringContainsString('remote-address="pool-pppoe"', $script);
        $this->assertStringContainsString('authentication=pap', $script);
        $this->assertStringContainsString('service=ppp', $script);
        $this->assertStringNotContainsString('service=hotspot', $script);
        $this->assertStringNotContainsString('/ip hotspot', $script);
    }

    public function test_combined_hotspot_and_pppoe_registers_both_radius_services(): void
    {
        config([
            'radius.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
            'tenancy.base_domain' => 'example.test',
        ]);
        $router = $this->makeConnectedRouter();
        $this->mockOperationalRadius();
        $router->forceFill([
            'hardware_model' => 'RB4011',
            'enable_pppoe' => true,
            'pppoe_config' => [
                'interface' => 'ether3',
                'vlan_id' => null,
                'network' => '10.20.0.0/16',
                'gateway' => '10.20.0.1',
                'dns' => '1.1.1.1,8.8.8.8',
            ],
        ])->saveOrFail();
        $script = null;
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($this->safeRouterState());
        $connection->shouldReceive('createProvisioningBackup')->once()->ordered()->andReturn('wifi-billing-pre-test.backup');
        $connection->shouldReceive('executeOnboardingScript')
            ->once()
            ->ordered()
            ->withArgs(function (Router $target, string $source) use (&$script, $router): bool {
                $this->assertSame($router->id, $target->id);
                $script = $source;

                return true;
            });
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router->fresh(), true);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('service=hotspot,ppp', $script);
        $this->assertStringContainsString('authentication=pap', $script);
    }

    public function test_pppoe_rejects_chr_and_overlapping_networks(): void
    {
        config([
            'radius.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
            'tenancy.base_domain' => 'example.test',
        ]);
        $service = app(RouterProvisioningService::class);
        $this->assertTrue($service->networksOverlap('10.20.0.0/16', '10.20.10.0/24'));
        $this->assertFalse($service->networksOverlap('10.20.0.0/16', '10.21.0.0/16'));

        $router = $this->makeConnectedRouter();
        $router->forceFill([
            'hardware_model' => 'CHR',
            'enable_hotspot' => false,
            'enable_pppoe' => true,
        ])->saveOrFail();

        $result = $service->deployGuided($router->fresh(), true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not available for CHR', $result['message']);

        $router->forceFill([
            'hardware_model' => 'RB4011',
            'enable_hotspot' => true,
            'enable_pppoe' => true,
            'pppoe_config' => [
                'interface' => 'ether2',
                'network' => '10.20.0.0/16',
                'gateway' => '10.20.0.1',
                'dns' => '1.1.1.1',
            ],
        ])->saveOrFail();
        $this->mockOperationalRadius();
        $sameInterface = $service->deployGuided($router->fresh(), true);

        $this->assertFalse($sameInterface['ok']);
        $this->assertStringContainsString('distinct WAN, management', $sameInterface['message']);
    }

    public function test_live_preflight_conflict_stops_before_backup_or_configuration_writes(): void
    {
        config(['radius.enabled' => true]);
        $this->mockOperationalRadius();
        $router = $this->makeConnectedRouter();
        $state = $this->safeRouterState();
        $state['resource']['version'] = '6.49.10';
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($state);
        $connection->shouldReceive('createProvisioningBackup')->never();
        $connection->shouldReceive('executeOnboardingScript')->never();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('RouterOS 7.x', $result['message']);
    }

    public function test_failed_backup_prevents_all_service_configuration_writes(): void
    {
        config(['radius.enabled' => true]);
        $this->mockOperationalRadius();
        $router = $this->makeConnectedRouter();
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($this->safeRouterState());
        $connection->shouldReceive('createProvisioningBackup')->once()
            ->andThrow(new RouterTransportException('Router backup could not be verified. No provisioning changes were made.'));
        $connection->shouldReceive('executeOnboardingScript')->never();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('backup could not be verified', $result['message']);
        $this->assertNull($result['backup_name']);
    }

    public function test_active_builtin_wifi_is_refused_without_any_router_changes(): void
    {
        config(['radius.enabled' => true]);
        $this->mockOperationalRadius();
        $router = $this->makeConnectedRouter();
        $state = $this->safeRouterState();
        $state['all_interfaces'][] = ['name' => 'wlan1', 'type' => 'wlan', 'disabled' => 'false'];
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($state);
        $connection->shouldReceive('createProvisioningBackup')->never();
        $connection->shouldReceive('executeOnboardingScript')->never();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('built-in Wi-Fi radios', $result['message']);
    }

    public function test_missing_wan_nat_is_refused_before_backup(): void
    {
        config(['radius.enabled' => true]);
        $this->mockOperationalRadius();
        $router = $this->makeConnectedRouter();
        $state = $this->safeRouterState();
        $state['nat_rules'] = [];
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('inspectProvisioningTarget')->once()->andReturn($state);
        $connection->shouldReceive('createProvisioningBackup')->never();
        $connection->shouldReceive('executeOnboardingScript')->never();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('masquerade rule', $result['message']);
    }

    public function test_overlapping_hotspot_and_pppoe_networks_are_refused_in_the_service_layer(): void
    {
        config(['radius.enabled' => true]);
        $this->mockOperationalRadius();
        $router = $this->makeConnectedRouter();
        $router->forceFill([
            'enable_pppoe' => true,
            'pppoe_config' => [
                'interface' => 'ether3',
                'network' => '10.10.0.0/16',
                'gateway' => '10.10.0.1',
                'dns' => '1.1.1.1',
            ],
        ])->saveOrFail();
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldNotReceive('inspectProvisioningTarget');
        $connection->shouldNotReceive('createProvisioningBackup');
        $connection->shouldNotReceive('executeOnboardingScript');
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterProvisioningService::class)->deployGuided($router->fresh(), true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('networks overlap', $result['message']);
    }

    public function test_interface_role_selectors_render_inside_the_service_configuration_form(): void
    {
        $router = $this->makeConnectedRouter();
        $router->forceFill(['enrolled' => true])->saveOrFail();
        $tenant = $router->tenant;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $user->assignRole('operator_admin');

        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('onboardingInterfaces')->once()->andReturn([
            ['name' => 'ether1'],
            ['name' => 'ether2'],
            ['name' => 'ether5'],
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        $response = $this->actingAs($user)->get(route('operator.routers.onboarding.services', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, preg_match(
            '/<form\b[^>]*action="[^"]*onboarding\/services"[^>]*>(.*?)<\/form>/s',
            $html,
            $form,
        ));
        $this->assertStringContainsString('name="wan_interface"', $form[1]);
        $this->assertStringContainsString('name="management_interface"', $form[1]);
    }

    private function mockOperationalRadius(): void
    {
        $this->mock(SystemHealthService::class, function ($mock): void {
            $mock->shouldReceive('queueWorkerReady')->andReturn(true);
        });
        $this->mock(RadiusRuntimeHealthCheck::class, function ($mock): void {
            $mock->shouldReceive('available')->andReturn(true);
        });
    }

    private function safeRouterState(): array
    {
        $ports = [];
        for ($number = 1; $number <= 5; $number++) {
            $ports[] = ['name' => 'ether'.$number, 'running' => 'true'];
        }

        return [
            'ok' => true,
            'resource' => ['version' => '7.14.3 (stable)'],
            'all_interfaces' => [],
            'interfaces' => $ports,
            'default_routes' => [[
                'dst-address' => '0.0.0.0/0',
                'gateway' => '192.0.2.1',
                'immediate-gw' => '192.0.2.1%ether1',
                'active' => 'true',
                'disabled' => 'false',
            ]],
            'interface_list_members' => [[
                'list' => 'WAN',
                'interface' => 'ether1',
                'disabled' => 'false',
            ]],
            'nat_rules' => [[
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'out-interface-list' => 'WAN',
                'disabled' => 'false',
            ]],
            'bridges' => [['name' => 'bridge', 'comment' => 'defconf']],
            'bridge_ports' => [['interface' => 'ether2', 'bridge' => 'bridge', 'comment' => 'defconf']],
            'addresses' => [],
            'dhcp_servers' => [],
            'dhcp_networks' => [],
            'hotspot_servers' => [],
            'hotspot_profiles' => [],
            'pools' => [],
            'pppoe_servers' => [],
            'ppp_profiles' => [],
            'vlan_interfaces' => [],
            'radius_clients' => [],
            'ppp_active_count' => 0,
            'ppp_secret_count' => 0,
        ];
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
            'api_port' => 8729,
            'enable_hotspot' => true,
            'hotspot_config' => [
                'network' => '10.10.0.0/16',
                'gateway' => '10.10.0.1',
                'dns' => '1.1.1.1,8.8.8.8',
                'domain' => 'hotspot.example.test',
                'wan_interface' => 'ether1',
                'management_interface' => 'ether5',
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
            'status' => 'radius_ready',
            'expires_at' => now()->addMinutes(30),
            'connected_at' => now(),
        ]);

        return $router->fresh(['vpnPeer', 'onboarding']);
    }
}
