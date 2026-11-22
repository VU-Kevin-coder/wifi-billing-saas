<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPeer;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZillEAli\MikrotikLaravel\Facades\MikroTik;

class RouterProvisioningSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_and_api_fail_closed_even_with_opt_in_and_direct_connection(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'Synthetic', 'slug' => 'synthetic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        $router = Router::create([
            'tenant_id' => $tenant->id, 'name' => 'Synthetic', 'management_ip' => '192.0.2.1',
            'api_username' => 'synthetic', 'api_password' => null, 'status' => 'provisioning',
        ]);
        MikroTik::shouldReceive('router')->never();
        $this->actingAs($user);
        $params = ['tenant' => $tenant->slug, 'router' => $router->id];
        $this->get(route('operator.routers.provision-script', $params + ['download' => 1, 'opt_in' => 1]))
            ->assertStatus(423)->assertSee(RouterProvisioningService::SAFETY_BLOCK);
        $this->getJson(route('operator.routers.provision-script', $params + ['opt_in' => 1]))
            ->assertStatus(423)->assertJsonPath('success', false);
        $this->assertNull($router->fresh()->api_password);
        $router->forceFill(['api_password' => 'synthetic-password'])->save();
        $this->postJson(route('operator.routers.provision', $params), ['opt_in' => true, 'confirmed' => true])
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertSame('synthetic-password', $router->fresh()->api_password);
        $this->assertSame('provisioning', $router->fresh()->status);
        $this->assertNull($router->fresh()->last_seen_at);
        $this->assertFalse((new RouterProvisioningService)->deploy($router)['ok']);
    }

    public function test_provisioning_requires_authorized_operator_and_does_not_mutate_router(): void
    {
        Role::create(['name' => 'operator_support', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'Synthetic', 'slug' => 'synthetic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_support');
        $router = Router::create([
            'tenant_id' => $tenant->id, 'name' => 'Synthetic', 'api_password' => null,
            'status' => 'provisioning',
        ]);
        $before = $router->fresh()->getRawOriginal();
        $params = ['tenant' => $tenant->slug, 'router' => $router->id];
        MikroTik::shouldReceive('router')->never();

        $this->getJson(route('operator.routers.provision-script', $params))->assertUnauthorized();
        $this->postJson(route('operator.routers.provision', $params))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('operator.routers.provision-script', $params))->assertForbidden();
        $this->actingAs($user)->postJson(route('operator.routers.provision', $params))->assertForbidden();
        $this->assertSame($before, $router->fresh()->getRawOriginal());
    }

    public function test_script_alias_is_also_blocked(): void
    {
        $router = new Router;
        try {
            (new RouterProvisioningService)->generateProvisioningScript($router);
            $this->fail('Script generation must be blocked.');
        } catch (\LogicException $e) {
            $this->assertSame(RouterProvisioningService::SAFETY_BLOCK, $e->getMessage());
        }
    }

    public function test_temporary_wireless_script_requires_local_lab_opt_in_and_saved_wireless_settings(): void
    {
        $service = new RouterProvisioningService;
        $router = new Router;
        config(['router.lab_provisioning_enabled' => false]);

        try {
            $service->buildTemporaryWirelessTestScript($router);
            $this->fail('Temporary wireless scripts must be disabled without lab opt-in.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('enabled local lab environments', $exception->getMessage());
        }

        config(['router.lab_provisioning_enabled' => true]);
        $router->forceFill(['has_wireless' => true, 'wifi_ssid' => null]);
        try {
            $service->buildTemporaryWirelessTestScript($router);
            $this->fail('A test script must require a saved SSID.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('save an SSID', $exception->getMessage());
        }
    }

    public function test_radius_script_is_limited_to_an_enabled_local_chr_and_does_not_deploy(): void
    {
        config([
            'radius.enabled' => true,
            'router.lab_provisioning_enabled' => true,
            'wireguard.enabled' => true,
            'wireguard.server_address' => '10.8.0.1',
        ]);
        $tenant = Tenant::create(['name' => 'CHR Lab', 'slug' => 'chr-lab', 'status' => 'active']);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lab CHR',
            'hardware_model' => 'CHR',
        ]);
        $secret = str_repeat('a', 64);
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'chr-lab-nas',
            'radius_shared_secret' => $secret,
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Lab peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);

        $script = app(RouterProvisioningService::class)->generateRadiusLabScript($router->fresh(), $secret);
        $this->assertStringContainsString('secret="'.$secret.'"', $script);
        $this->assertStringContainsString('src-address="10.8.1.20"', $script);
        $this->assertStringContainsString('use-radius=yes radius-accounting=yes', $script);
        $this->assertStringNotContainsString('/user add', $script);
        $this->assertFalse(app(RouterProvisioningService::class)->deploy($router)['ok']);
    }
}
