<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPeer;
use App\Services\RouterConnectionService;
use App\Services\Tenancy\TenantContext;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RouterManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;

    protected Tenant $beta;

    protected User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $this->acme = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $this->beta = Tenant::create(['name' => 'Beta Networks', 'slug' => 'beta', 'status' => 'active']);

        $this->acmeUser = User::factory()->create([
            'tenant_id' => $this->acme->id,
            'name' => 'Acme Operator',
            'email' => 'operator@acme.test',
        ]);
        $this->acmeUser->assignRole('operator_admin');
    }

    public function test_router_list_page_can_be_rendered_with_kpis(): void
    {
        $site = $this->acme->sites()->create(['name' => 'Central Office']);
        app(TenantContext::class)->runAs($this->acme, function () use ($site): void {
            Router::create([
                'name' => 'CENTRAL-ROUTER',
                'site_id' => $site->id,
                'site' => $site->name,
                'api_password' => 'password123',
            ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost/operator/routers');

        $response->assertStatus(200);
        $response->assertSee('MikroTik routers');
        $response->assertSee('Connected routers');
        $response->assertSee('Online now');
        $response->assertSee('Offline');
        $response->assertSee('Average CPU load');
        $response->assertSee('Central Office');
        $response->assertSee('Test LAN API');
    }

    public function test_router_provisioning_wizard_can_be_rendered(): void
    {
        $site = $this->acme->sites()->create(['name' => 'Central Office']);

        $response = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost/operator/routers/create');

        $response->assertStatus(200);
        $response->assertSee('Add MikroTik Router');
        $response->assertSee('password is encrypted at rest');
        $response->assertSee('Allowed API source subnet');
        $response->assertSee('Operating site');
        $response->assertSee('Central Office');
        $response->assertSee('value="'.$site->id.'"', false);
        $response->assertSee('Service setup');
    }

    public function test_operator_can_create_new_router(): void
    {
        $response = $this->actingAs($this->acmeUser)
            ->post('http://acme.localhost/operator/routers', [
                'name' => 'TEST-ROUTER-01',
                'management_ip' => '10.20.1.10',
                'management_subnet' => '10.20.1.0/24',
                'api_port' => 8729,
                'api_username' => 'router-api',
                'site' => 'Kampala Central',
                'hardware_model' => 'CCR2004',
                'api_password' => 'supersecretpass123',
                'has_wireless' => 1,
                'enable_hotspot' => 1,
                'hotspot_network' => '10.10.0.0/16',
                'gateway_ip' => '10.10.0.1',
                'dns_servers' => '8.8.8.8,8.8.4.4',
                'hotspot_domain' => 'hotspot.local',
                'ethernet_ports' => ['ether2', 'ether3'],
                '_idempotency_key' => $this->idempotencyKey(),
            ]);

        $response->assertRedirect();

        $router = Router::where('name', 'TEST-ROUTER-01')->first();
        $this->assertNotNull($router);
        $this->assertEquals($this->acme->id, $router->tenant_id);
        $this->assertEquals('provisioning', $router->status);
        $this->assertEquals('CCR2004', $router->hardware_model);
        $this->assertSame('10.20.1.10', $router->management_ip);
        $this->assertEquals(8729, $router->api_port);
        $this->assertSame('router-api', $router->api_username);
        $this->assertTrue($router->has_wireless);
        $this->assertSame('supersecretpass123', $router->api_password);
        $this->assertSame('10.20.1.0/24', $router->hotspot_config['management_subnet']);

        $script = $this->actingAs($this->acmeUser)
            ->get(route('operator.routers.provision-script', [
                'tenant' => 'acme',
                'router' => $router->id,
                'download' => 1,
            ]));

        $script->assertOk();
        $this->assertStringContainsString('password="supersecretpass123"', $script->getContent());
    }

    public function test_router_detail_page_can_be_rendered(): void
    {
        $site = $this->acme->sites()->create(['name' => 'Ntinda Hub']);
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () use ($site) {
            return Router::create([
                'name' => 'CORE-MKT-01',
                'hardware_model' => 'RB4011',
                'management_ip' => '10.20.1.1',
                'site_id' => $site->id,
                'site' => 'Ntinda Hub',
                'status' => 'online',
                'cpu_usage' => 24,
                'memory_usage' => 45,
                'api_username' => 'sopraent_user',
                'api_password' => 'pass123456',
            ]);
        });

        $this->assertSame($site->name, Site::withoutTenancy()->findOrFail($site->id)->name);

        $this->mock(RouterConnectionService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('liveStatus')
                ->once()
                ->withArgs(fn (Router $testedRouter): bool => $testedRouter->is($router))
                ->andReturn([
                    'ok' => true,
                    'message' => 'Router API responded successfully.',
                    'cpu_load' => 24,
                    'memory_usage' => 45,
                    'uptime' => '3d12h',
                    'active_users' => 7,
                ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/{$router->id}");

        $response->assertStatus(200);
        $response->assertSee('CORE-MKT-01');
        $response->assertSee('CPU Load');
        $response->assertSee('24%');
        $response->assertSee('Memory Usage');
        $response->assertSee('45%');
        $response->assertSee('3d12h');
        $response->assertSee('7');
        $response->assertSee('Router Details');
        $response->assertSee('Ntinda Hub');
        $response->assertSee('Connection Status');
        $response->assertSee('Test Router API');
        $response->assertDontSee('pass123456');
    }

    public function test_router_detail_page_does_not_report_zero_metrics_when_live_connection_fails(): void
    {
        $router = app(TenantContext::class)->runAs($this->acme, function () {
            return Router::create([
                'name' => 'UNREACHABLE-ROUTER',
                'management_ip' => '10.20.1.99',
                'status' => 'offline',
            ]);
        });

        $this->mock(RouterConnectionService::class, function ($mock): void {
            $mock->shouldReceive('liveStatus')
                ->once()
                ->andReturn([
                    'ok' => false,
                    'message' => 'Add a host/IP, API username and API password before testing.',
                    'cpu_load' => 0,
                    'memory_usage' => 0,
                    'uptime' => 'N/A',
                    'active_users' => 0,
                ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/{$router->id}");

        $response->assertOk()
            ->assertSee('Live data unavailable')
            ->assertSee('—')
            ->assertSee('N/A')
            ->assertSee('Add a host/IP, API username and API password before testing.');
    }

    public function test_operator_can_edit_router_details_and_preserve_existing_api_password(): void
    {
        $site = $this->acme->sites()->create(['name' => 'Updated site']);
        $router = $this->acme->routers()->create([
            'name' => 'Old router name',
            'management_ip' => '192.0.2.10',
            'api_username' => 'router-api',
            'api_password' => 'existing-secret',
            'hotspot_config' => [
                'network' => '10.10.0.0/16',
                'gateway' => '10.10.0.1',
                'dns' => '8.8.8.8,8.8.4.4',
                'domain' => 'hotspot.local',
                'management_subnet' => '192.0.2.0/24',
            ],
            'ethernet_ports' => ['ether2'],
        ]);

        $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/{$router->id}/edit")
            ->assertOk()
            ->assertSee('Edit router')
            ->assertSee('Old router name')
            ->assertSee('Leave blank to keep the current encrypted password.');

        $response = $this->actingAs($this->acmeUser)
            ->put("http://acme.localhost/operator/routers/{$router->id}", [
                'name' => 'Updated router',
                'serial_number' => 'SN-123',
                'site_id' => $site->id,
                'hardware_model' => 'RB4011',
                'management_ip' => 'hamnet-chr',
                'management_subnet' => '192.0.2.0/24',
                'api_username' => 'updated-api',
                'api_password' => '',
                'api_port' => 8729,
                'has_wireless' => 1,
                'enable_hotspot' => 1,
                'hotspot_network' => '10.20.0.0/16',
                'gateway_ip' => '10.20.0.1',
                'dns_servers' => '1.1.1.1,8.8.8.8',
                'hotspot_domain' => 'wifi.example.test',
                'ethernet_ports' => ['ether3', 'ether4'],
            ]);

        $response->assertRedirect(route('operator.routers.show', [
            'tenant' => $this->acme->slug,
            'router' => $router->id,
        ]));

        $router->refresh();
        $this->assertSame('Updated router', $router->name);
        $this->assertSame('SN-123', $router->serial_number);
        $this->assertSame($site->id, $router->site_id);
        $this->assertSame('Updated site', $router->site);
        $this->assertSame('RB4011', $router->hardware_model);
        $this->assertSame('hamnet-chr', $router->management_ip);
        $this->assertSame('updated-api', $router->api_username);
        $this->assertSame('existing-secret', $router->api_password);
        $this->assertSame(8729, $router->api_port);
        $this->assertTrue($router->has_wireless);
        $this->assertTrue($router->enable_hotspot);
        $this->assertSame('10.20.0.0/16', $router->hotspot_config['network']);
        $this->assertSame('10.20.0.1', $router->hotspot_config['gateway']);
        $this->assertSame(['ether3', 'ether4'], $router->ethernet_ports);
    }

    public function test_router_edit_rejects_site_from_another_tenant(): void
    {
        $router = $this->acme->routers()->create(['name' => 'Acme router']);
        $foreignSite = $this->beta->sites()->create(['name' => 'Beta site']);

        $response = $this->actingAs($this->acmeUser)
            ->from("http://acme.localhost/operator/routers/{$router->id}/edit")
            ->put("http://acme.localhost/operator/routers/{$router->id}", [
                'name' => 'Should not save',
                'site_id' => $foreignSite->id,
                'api_port' => 8728,
            ]);

        $response->assertRedirect("http://acme.localhost/operator/routers/{$router->id}/edit")
            ->assertSessionHasErrors('site_id');
        $this->assertSame('Acme router', $router->fresh()->name);
    }

    public function test_operator_can_generate_wireguard_peer_for_tenant_router(): void
    {
        $router = $this->acme->routers()->create(['name' => 'CHR']);
        $peer = new VpnPeer;

        $this->mock(WireGuardService::class, function ($mock) use ($router, $peer): void {
            $mock->shouldReceive('createPeer')
                ->once()
                ->withArgs(fn (Router $requestedRouter, string $name): bool => $requestedRouter->is($router) && $name === 'CHR')
                ->andReturn($peer);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$router->id}/vpn-peer");

        $response->assertRedirect(route('operator.routers.show', [
            'tenant' => $this->acme->slug,
            'router' => $router->id,
        ]));
    }

    public function test_operator_can_delete_router(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'DELETABLE-ROUTER',
                'hardware_model' => 'hEX',
                'api_password' => 'pass123456',
            ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->delete("http://acme.localhost/operator/routers/{$router->id}");

        $response->assertRedirect('http://acme.localhost/operator/routers');
    }

    public function test_router_with_active_sessions_cannot_be_deleted(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create(['name' => 'ACTIVE-SESSION-ROUTER']);
        });
        Session::create([
            'tenant_id' => $this->acme->id,
            'router_id' => $router->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($this->acmeUser)
            ->delete("http://acme.localhost/operator/routers/{$router->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('router');

        $this->assertDatabaseHas('routers', ['id' => $router->id]);
    }

    public function test_routers_are_isolated_between_tenants(): void
    {
        $context = app(TenantContext::class);

        $context->runAs($this->acme, function () {
            Router::create([
                'name' => 'ACME-ROUTER-01',
                'api_password' => 'secret123',
            ]);
        });

        $context->runAs($this->beta, function () {
            Router::create([
                'name' => 'BETA-ROUTER-01',
                'api_password' => 'secret456',
            ]);
        });

        $context->runAs($this->acme, function () {
            $this->assertEquals(1, Router::where('name', 'ACME-ROUTER-01')->count());
            $this->assertEquals(0, Router::where('name', 'BETA-ROUTER-01')->count());
        });

        $context->runAs($this->beta, function () {
            $this->assertEquals(0, Router::where('name', 'ACME-ROUTER-01')->count());
            $this->assertEquals(1, Router::where('name', 'BETA-ROUTER-01')->count());
        });
    }

    public function test_operator_can_ping_router(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'PING-TEST-ROUTER',
                'status' => 'offline',
                'api_password' => 'secret123',
            ]);
        });

        $this->mock(RouterConnectionService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (Router $testedRouter): bool => $testedRouter->is($router))
                ->andReturn(['ok' => true, 'message' => 'Router API responded successfully.']);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$router->id}/ping");

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $router->refresh();
        $this->assertEquals('online', $router->status);
        $this->assertNotNull($router->last_seen_at);
    }

    public function test_operator_can_test_router_lan_api(): void
    {
        $router = app(TenantContext::class)->runAs($this->acme, function () {
            return Router::create([
                'name' => 'LAN-API-TEST-ROUTER',
                'api_password' => 'secret123',
            ]);
        });

        $this->mock(RouterConnectionService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (Router $testedRouter): bool => $testedRouter->is($router))
                ->andReturn(['ok' => true, 'message' => 'Router API responded successfully.']);
        });

        $this->actingAs($this->acmeUser)
            ->postJson("http://acme.localhost/operator/routers/{$router->id}/test")
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Router API responded successfully.',
            ]);
    }

    public function test_operator_receives_failure_when_router_api_test_fails(): void
    {
        $router = app(TenantContext::class)->runAs($this->acme, function () {
            return Router::create([
                'name' => 'LAN-API-OFFLINE',
                'api_password' => 'secret123',
            ]);
        });

        $this->mock(RouterConnectionService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (Router $testedRouter): bool => $testedRouter->is($router))
                ->andReturn(['ok' => false, 'message' => 'Router API did not respond.']);
        });

        $this->actingAs($this->acmeUser)
            ->postJson("http://acme.localhost/operator/routers/{$router->id}/test")
            ->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'message' => 'Router API did not respond.',
            ]);
    }

    public function test_operator_reboot_is_not_reported_as_success_without_agent_support(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'REBOOT-TEST-ROUTER',
                'status' => 'online',
                'api_password' => 'secret123',
            ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$router->id}/reboot");

        $response->assertRedirect();
        $response->assertSessionHasErrors('router');
    }

    public function test_operator_sync_is_not_reported_as_success_without_agent_support(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'SYNC-TEST-ROUTER',
                'status' => 'provisioning',
                'api_password' => 'secret123',
            ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$router->id}/sync");

        $response->assertRedirect();
        $response->assertSessionHasErrors('router');

        $router->refresh();
        $this->assertEquals('provisioning', $router->status);
    }

    public function test_operator_can_ping_all_routers(): void
    {
        $context = app(TenantContext::class);
        $context->runAs($this->acme, function () {
            Router::create(['name' => 'ROUTER-A', 'status' => 'online', 'api_password' => 'p1']);
            Router::create(['name' => 'ROUTER-B', 'status' => 'online', 'api_password' => 'p2']);
        });
        $this->mock(RouterConnectionService::class, function ($mock): void {
            $mock->shouldReceive('test')
                ->twice()
                ->andReturn(['ok' => false, 'message' => 'No site agent paired.']);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post('http://acme.localhost/operator/routers/ping-all');

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_bulk_ping_is_not_reported_as_success_without_agent_support(): void
    {
        $context = app(TenantContext::class);
        $routerIds = $context->runAs($this->acme, function () {
            $r1 = Router::create(['name' => 'BULK-1', 'status' => 'offline', 'api_password' => 'p1']);
            $r2 = Router::create(['name' => 'BULK-2', 'status' => 'offline', 'api_password' => 'p2']);

            return [$r1->id, $r2->id];
        });

        $response = $this->actingAs($this->acmeUser)
            ->post('http://acme.localhost/operator/routers/bulk-action', [
                'action' => 'ping',
                'router_ids' => $routerIds,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('bulk');

        $r1 = Router::find($routerIds[0]);
        $this->assertEquals('offline', $r1->status);
    }

    public function test_cross_tenant_actions_are_strictly_forbidden(): void
    {
        $context = app(TenantContext::class);

        // Beta's router
        $betaRouter = $context->runAs($this->beta, function () {
            return Router::create([
                'name' => 'BETA-PRIVATE-ROUTER',
                'api_password' => 'betapass123',
            ]);
        });

        // Acme user attempts to access / perform action on Beta's router -> MUST return 404
        $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/{$betaRouter->id}")
            ->assertStatus(404);

        $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$betaRouter->id}/ping")
            ->assertStatus(404);

        $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$betaRouter->id}/reboot")
            ->assertStatus(404);

        $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$betaRouter->id}/sync")
            ->assertStatus(404);

        $this->actingAs($this->acmeUser)
            ->postJson("http://acme.localhost/operator/routers/{$betaRouter->id}/test")
            ->assertStatus(404);

        $this->actingAs($this->acmeUser)
            ->delete("http://acme.localhost/operator/routers/{$betaRouter->id}")
            ->assertStatus(404);

        // Beta router is still intact in the database
        $this->assertDatabaseHas('routers', ['id' => $betaRouter->id]);
    }

    public function test_router_settings_sync_all_and_disconnect(): void
    {
        $this->acme->update([
            'mikrotik_host' => '192.168.88.1',
            'mikrotik_username' => 'admin',
            'mikrotik_password' => 'mypass',
            'mikrotik_port' => 8728,
        ]);

        // Sync all routers
        $response = $this->actingAs($this->acmeUser)
            ->post('http://acme.localhost/operator/settings/router/sync-all');

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // Disconnect router
        $disconnectResponse = $this->actingAs($this->acmeUser)
            ->post('http://acme.localhost/operator/settings/router/disconnect');

        $disconnectResponse->assertRedirect();
        $this->acme->refresh();
        $this->assertNull($this->acme->mikrotik_host);
        $this->assertNull($this->acme->mikrotik_username);
    }

    public function test_deleting_all_routers_does_not_display_any_fake_data_or_auto_seed(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'TEMP-ROUTER-01',
                'hardware_model' => 'CCR2004',
                'api_password' => 'secret123',
                'status' => 'online',
            ]);
        });

        // 1. Delete the router
        $response = $this->actingAs($this->acmeUser)
            ->delete("http://acme.localhost/operator/routers/{$router->id}");

        $response->assertRedirect('http://acme.localhost/operator/routers');

        // Assert 0 routers in database for Acme
        $context->runAs($this->acme, function () {
            $this->assertEquals(0, Router::count());
        });

        // 2. Visit routers page again - must NOT auto-seed fake routers
        $listResponse = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost/operator/routers');

        $listResponse->assertStatus(200);
        $listResponse->assertSee('No routers found in workspace');
        $listResponse->assertSee('Across 0 operating site(s)');
        $listResponse->assertDontSee('NTD-CORE-01');
        $listResponse->assertDontSee('Jinja-MKT-02');
        $listResponse->assertDontSee('BGL-CORE-01');

        $context->runAs($this->acme, function () {
            $this->assertEquals(0, Router::count());
        });

        // 3. Visit dashboard - must NOT show fake router stats or alerts
        $dashResponse = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost/operator/dashboard');

        $dashResponse->assertStatus(200);
        $dashResponse->assertSee('0 / 0');
        $dashResponse->assertSee('No routers connected');
        $dashResponse->assertDontSee('Jinja-MKT-02 has been offline');
        $dashResponse->assertDontSee('11 / 12');

        // 4. Visit sessions page - must NOT show fake demo sessions
        $sessionsResponse = $this->actingAs($this->acmeUser)
            ->get('http://acme.localhost/operator/sessions');

        $sessionsResponse->assertStatus(200);
        $sessionsResponse->assertSee('No active sessions right now');
        $sessionsResponse->assertDontSee('Amina Namusoke');
        $sessionsResponse->assertDontSee('Brian Kato');
    }
}
