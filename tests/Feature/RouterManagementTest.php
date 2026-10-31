<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
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

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

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
        $response = $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers");

        $response->assertStatus(200);
        $response->assertSee('MikroTik routers');
        $response->assertSee('Connected routers');
        $response->assertSee('Online now');
        $response->assertSee('Offline');
        $response->assertSee('Average CPU load');
    }

    public function test_router_provisioning_wizard_can_be_rendered(): void
    {
        $response = $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/create");

        $response->assertStatus(200);
        $response->assertSee('Add Mikrotik Device');
        $response->assertSee('MikroTik Identity');
        $response->assertSee('Provisioning Command');
        $response->assertSee('/tool fetch mode=https');
        $response->assertSee('Service Setup');
    }

    public function test_operator_can_create_new_router(): void
    {
        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers", [
                'name' => 'TEST-ROUTER-01',
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
            ]);

        $response->assertRedirect();

        $router = Router::where('name', 'TEST-ROUTER-01')->first();
        $this->assertNotNull($router);
        $this->assertEquals($this->acme->id, $router->tenant_id);
        $this->assertEquals('provisioning', $router->status);
        $this->assertEquals('CCR2004', $router->hardware_model);
        $this->assertTrue($router->has_wireless);
        $this->assertEquals('supersecretpass123', $router->api_password);
    }

    public function test_router_detail_page_can_be_rendered(): void
    {
        $context = app(TenantContext::class);
        $router = $context->runAs($this->acme, function () {
            return Router::create([
                'name' => 'CORE-MKT-01',
                'hardware_model' => 'RB4011',
                'management_ip' => '10.20.1.1',
                'site' => 'Ntinda Hub',
                'status' => 'online',
                'cpu_usage' => 24,
                'memory_usage' => 45,
                'api_username' => 'sopraent_user',
                'api_password' => 'pass123456',
            ]);
        });

        $response = $this->actingAs($this->acmeUser)
            ->get("http://acme.localhost/operator/routers/{$router->id}");

        $response->assertStatus(200);
        $response->assertSee('CORE-MKT-01');
        $response->assertSee('System Information');
        $response->assertSee('General Information');
        $response->assertSee('RADIUS Configuration');
        $response->assertSee('Device Information');
        $response->assertSee('Connection Health');
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

        $response->assertRedirect("http://acme.localhost/operator/routers");
        $this->assertDatabaseMissing('routers', ['id' => $router->id]);
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

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/{$router->id}/ping");

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $router->refresh();
        $this->assertEquals('online', $router->status);
        $this->assertNotNull($router->last_seen_at);
    }

    public function test_operator_can_reboot_router(): void
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
        $response->assertSessionHas('status');
    }

    public function test_operator_can_sync_router(): void
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
        $response->assertSessionHas('status');

        $router->refresh();
        $this->assertEquals('online', $router->status);
    }

    public function test_operator_can_ping_all_routers(): void
    {
        $context = app(TenantContext::class);
        $context->runAs($this->acme, function () {
            Router::create(['name' => 'ROUTER-A', 'status' => 'online', 'api_password' => 'p1']);
            Router::create(['name' => 'ROUTER-B', 'status' => 'online', 'api_password' => 'p2']);
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/ping-all");

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_operator_can_perform_bulk_actions(): void
    {
        $context = app(TenantContext::class);
        $routerIds = $context->runAs($this->acme, function () {
            $r1 = Router::create(['name' => 'BULK-1', 'status' => 'offline', 'api_password' => 'p1']);
            $r2 = Router::create(['name' => 'BULK-2', 'status' => 'offline', 'api_password' => 'p2']);
            return [$r1->id, $r2->id];
        });

        $response = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/routers/bulk-action", [
                'action' => 'ping',
                'router_ids' => $routerIds,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $r1 = Router::find($routerIds[0]);
        $this->assertEquals('online', $r1->status);
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
            ->post("http://acme.localhost/operator/settings/router/sync-all");

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // Disconnect router
        $disconnectResponse = $this->actingAs($this->acmeUser)
            ->post("http://acme.localhost/operator/settings/router/disconnect");

        $disconnectResponse->assertRedirect();
        $this->acme->refresh();
        $this->assertNull($this->acme->mikrotik_host);
        $this->assertNull($this->acme->mikrotik_username);
    }
}
