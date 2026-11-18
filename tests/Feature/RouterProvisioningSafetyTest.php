<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
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
        config(['site_agent.required' => false, 'site_agent.allow_direct_router_connection' => true]);
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
            ->assertStatus(423)->assertSee('Router provisioning is disabled');
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
}
