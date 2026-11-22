<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DynamicMikrotikRouterConfig;
use App\Services\RouterConnectionService;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;
use ZillEAli\MikrotikLaravel\MikrotikManager;

class RouterProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operatorAdmin;

    private Router $router;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'status' => 'active',
        ]);

        $this->operatorAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
        ]);
        $this->operatorAdmin->assignRole('operator_admin');

        $this->package = Package::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Daily Pass',
            'price' => 3000,
            'currency' => 'UGX',
            'duration_value' => 24,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 15,
            'device_limit' => 2,
            'data_limit' => '5GB',
        ]);

        $this->router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main-Hotspot-Router',
            'hardware_model' => 'hEX RB750Gr3',
            'management_ip' => '192.168.88.1',
            'api_username' => 'billing_api',
            'api_password' => 'secret123',
            'api_port' => 8728,
            'status' => 'provisioning',
            'hotspot_config' => [
                'network' => '10.10.0.0/16',
                'gateway' => '10.10.0.1',
                'dns' => '8.8.8.8,1.1.1.1',
                'domain' => 'wifi.acme.local',
                'management_subnet' => '192.168.88.0/24',
            ],
            'ethernet_ports' => ['ether2', 'ether3', 'ether4'],
        ]);

        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    public function test_provisioning_service_blocks_script_and_preserves_package_profile(): void
    {
        $this->assertScriptBlockedWithoutMutation($this->router);
        $profile = $this->package->routerProfileSettings();
        $this->assertArrayNotHasKey('limit-uptime', $profile);
        $this->assertArrayNotHasKey('limit-bytes-total', $profile);
    }

    public function test_legacy_script_generation_remains_blocked_and_ssid_escaping_remains_safe(): void
    {
        $router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wireless-Router',
            'has_wireless' => true,
            'wifi_ssid' => 'Guest "$WiFi"',
        ]);
        $this->assertScriptBlockedWithoutMutation($router);
        $escaped = (new RouterProvisioningService)->escapeRouterOs($router->wifi_ssid);
        $this->assertStringContainsString('\\$', $escaped);
        $this->assertStringContainsString('\\"', $escaped);
    }

    public function test_default_username_does_not_bypass_provisioning_block(): void
    {
        config(['services.mikrotik.api_username' => 'router_api_default']);
        $this->router->forceFill(['api_username' => null])->save();

        $this->assertScriptBlockedWithoutMutation($this->router);
        $this->assertNull($this->router->fresh()->api_username);
    }

    public function test_platform_defaults_do_not_bypass_provisioning_block(): void
    {
        config(['services.mikrotik.gateway' => '192.168.20.1']);
        PlatformSetting::set('hotspot.default_network', '192.168.20.0/24');
        PlatformSetting::set('hotspot.default_dns', ['9.9.9.9']);
        PlatformSetting::set('hotspot.wan_interface', 'ether2');
        PlatformSetting::set('hotspot.walled_garden', ['example.org']);
        PlatformSetting::set('hotspot.idle_timeout', 7);
        PlatformSetting::set('router.password_length', 32);
        PlatformSetting::set('router.disable_insecure', true);

        $router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Defaulted-Router',
            'api_password' => null,
            'ethernet_ports' => ['ether2', 'ether3'],
        ]);
        $this->assertScriptBlockedWithoutMutation($router);
        $this->assertNull($router->fresh()->api_password);
    }

    public function test_provisioning_block_does_not_generate_password_and_escaping_remains_safe(): void
    {
        $router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Edge "HQ"\\$',
            'api_username' => 'billing_api',
            'api_password' => null,
            'api_port' => 8729,
            'hotspot_config' => [
                'network' => '192.168.20.0/24',
                'gateway' => '192.168.20.1',
                'dns' => '1.1.1.1',
                'domain' => 'hotspot.acme.test',
                'management_subnet' => '192.168.88.0/24',
            ],
        ]);
        $this->assertScriptBlockedWithoutMutation($router);
        $this->assertNull($router->fresh()->api_password);
        $escaped = (new RouterProvisioningService)->escapeRouterOs($router->name);
        $this->assertStringNotContainsString($router->name, $escaped);
    }

    public function test_safety_block_precedes_invalid_network_validation(): void
    {
        $this->router->forceFill([
            'hotspot_config' => [
                'network' => '10.10.0.0/24',
                'gateway' => '10.11.0.1',
                'dns' => '8.8.8.8',
                'domain' => 'wifi.acme.local',
            ],
        ])->save();

        // The safety gate takes precedence over validation until provisioning is restored.
        $this->assertScriptBlockedWithoutMutation($this->router);
    }

    public function test_dynamic_router_configuration_refreshes_the_resolved_manager(): void
    {
        $original = app(MikrotikManager::class);
        $key = 'test-router-'.$this->router->id;

        DynamicMikrotikRouterConfig::set($key, [
            'host' => $this->router->management_ip,
            'username' => $this->router->api_username,
            'password' => $this->router->api_password,
            'port' => $this->router->api_port,
        ]);

        $manager = app(MikrotikManager::class);

        $this->assertNotSame($original, $manager);
        $this->assertContains($key, $manager->getRouterNames());
    }

    public function test_operator_cannot_download_provisioning_script(): void
    {
        $response = $this->actingAs($this->operatorAdmin)
            ->get(route('operator.routers.provision-script', [
                'tenant' => $this->tenant->slug,
                'router' => $this->router->id,
                'download' => 1,
            ]));

        $response->assertStatus(423)->assertSee(RouterProvisioningService::SAFETY_BLOCK);
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $response->assertDontSee('secret123');
        $this->assertSame('provisioning', $this->router->fresh()->status);
    }

    public function test_router_show_page_renders_provisioning_section(): void
    {
        $this->mock(RouterConnectionService::class, function ($mock): void {
            $mock->shouldReceive('liveStatus')->andReturn([
                'ok' => false,
                'message' => 'N/A',
                'cpu_load' => 0,
                'memory_usage' => 0,
                'uptime' => 'N/A',
                'active_users' => 0,
            ]);
        });

        $response = $this->actingAs($this->operatorAdmin)
            ->get(route('operator.routers.show', [
                'tenant' => $this->tenant->slug,
                'router' => $this->router->id,
            ]));

        $response->assertOk();
        $response->assertSee('RouterOS Provisioning', false);
        $response->assertSee('Download .rsc');
        $response->assertSee('Deploy via API');
        $response->assertSee('10.10.0.0/16');
        $response->assertSee('10.10.0.1');
        $response->assertSee('wifi.acme.local');
    }

    public function test_automated_provisioning_does_not_deploy_to_mikrotik_via_api(): void
    {
        MikroTik::shouldReceive('router')->never();
        $before = $this->router->fresh()->getRawOriginal();
        $response = $this->actingAs($this->operatorAdmin)
            ->postJson(route('operator.routers.provision', [
                'tenant' => $this->tenant->slug,
                'router' => $this->router->id,
            ]), ['opt_in' => true, 'confirmed' => true]);

        $response->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJsonPath('message', RouterProvisioningService::SAFETY_BLOCK);
        $this->assertSame($before, $this->router->fresh()->getRawOriginal());
    }

    private function assertScriptBlockedWithoutMutation(Router $router): void
    {
        $before = $router->fresh()->getRawOriginal();
        try {
            (new RouterProvisioningService)->generateScript($router);
            $this->fail('Unsafe script generation must be blocked.');
        } catch (\LogicException $exception) {
            $this->assertSame(RouterProvisioningService::SAFETY_BLOCK, $exception->getMessage());
        }
        $this->assertSame($before, $router->fresh()->getRawOriginal());
    }
}
