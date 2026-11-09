<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterConnectionService;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Testing\MikrotikFake;

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
            ],
            'ethernet_ports' => ['ether2', 'ether3', 'ether4'],
        ]);

        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    public function test_provisioning_service_generates_routeros_script(): void
    {
        $service = new RouterProvisioningService;
        $script = $service->generateScript($this->router);

        $this->assertStringContainsString('/system identity set name="Main-Hotspot-Router"', $script);
        $this->assertStringContainsString('/interface bridge add name=br-hotspot', $script);
        $this->assertStringContainsString('/interface bridge port add bridge=br-hotspot interface=ether2', $script);
        $this->assertStringContainsString('/ip address add address=10.10.0.1/16 interface=br-hotspot', $script);
        $this->assertStringContainsString('/ip hotspot profile add name=prof-billing dns-name="wifi.acme.local"', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*pesapal.com*', $script);
        $this->assertStringContainsString('/ip hotspot walled-garden add dst-host=*mtn.co.ug*', $script);
        $this->assertStringContainsString('/ip service set api port=8728', $script);
        $this->assertStringContainsString('/ip hotspot user profile add name="Daily_Pass" rate-limit="15M/15M" shared-users=2', $script);
    }

    public function test_operator_can_download_provisioning_script(): void
    {
        $response = $this->actingAs($this->operatorAdmin)
            ->get(route('operator.routers.provision-script', [
                'tenant' => $this->tenant->slug,
                'router' => $this->router->id,
                'download' => 1,
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="mikrotik-provision-'.$this->router->id.'.rsc"');
        $this->assertStringContainsString('Main-Hotspot-Router', $response->getContent());
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

    public function test_automated_provisioning_deploys_to_mikrotik_via_api(): void
    {
        $fake = MikrotikFake::fake([
            '/system/resource/print' => [
                ['uptime' => '5d', 'cpu-load' => 10, 'free-memory' => 100000000, 'total-memory' => 256000000],
            ],
            '/ip/hotspot/user/profile/add' => [],
        ]);

        $response = $this->actingAs($this->operatorAdmin)
            ->postJson(route('operator.routers.provision', [
                'tenant' => $this->tenant->slug,
                'router' => $this->router->id,
            ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'profiles_synced' => 1,
            ]);

        $this->router->refresh();
        $this->assertSame('online', $this->router->status);
        $this->assertNotNull($this->router->last_seen_at);
    }
}
