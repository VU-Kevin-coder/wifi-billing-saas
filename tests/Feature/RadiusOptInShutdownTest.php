<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use App\Services\RadiusRouterCredentialService;
use App\Services\RadiusUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RadiusOptInShutdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.step_up_enabled' => false]);
        Policy::set('security.step_up_enabled', false);
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator_network', 'guard_name' => 'web']);
    }

    public function test_direct_service_opt_in_fails_without_mutating_either_model_or_database(): void
    {
        [$tenant, $router] = $this->fixture('service-block');
        $before = $router->getAttributes();
        $row = DB::table('routers')->where('id', $router->id)->first();
        try {
            app(RadiusRouterCredentialService::class)->optIn($router, 'synthetic-nas');
            $this->fail('RADIUS opt-in must be blocked');
        } catch (RadiusUnavailableException $e) {
            $this->assertSame(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE, $e->getMessage());
        }
        $this->assertSame($before, $router->getAttributes());
        $this->assertEquals($row, DB::table('routers')->where('id', $router->id)->first());
        $this->assertSame(0, AuditLog::query()->where('action', 'router.radius_credential_provisioned')->count());
    }

    public function test_crafted_authorized_request_is_rejected_without_creating_any_credential_or_audit(): void
    {
        [$tenant, $router, $user] = $this->fixture('http-block');
        $before = DB::table('routers')->where('id', $router->id)->first();
        $response = $this->actingAs($user)->post($this->url($tenant, $router).'/radius-credential', [
            'nas_identifier' => 'synthetic-nas', 'confirm_radius_mode' => '1',
            'access_backend' => 'radius', 'radius_shared_secret' => 'attacker-supplied-secret',
        ]);
        $response->assertStatus(503)->assertSee(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE)
            ->assertDontSee('attacker-supplied-secret')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        $this->assertSame(0, AuditLog::query()->where('action', 'router.radius_credential_provisioned')->count());
    }

    public function test_existing_radius_is_preserved_on_show_and_rejected_opt_in_but_can_be_explicitly_switched_to_site_agent(): void
    {
        [$tenant, $router, $user] = $this->fixture('existing-radius');
        $router->forceFill(['access_backend' => 'radius', 'radius_nas_identifier' => 'existing-nas', 'radius_shared_secret' => str_repeat('a', 64)])->save();
        $before = DB::table('routers')->where('id', $router->id)->first();
        $this->actingAs($user)->get($this->url($tenant, $router))->assertOk()
            ->assertSee('RADIUS access unavailable')->assertSee('This router is already configured for RADIUS')
            ->assertSee('This does not block all other router writes.')
            ->assertDontSee('Enable RADIUS and show secret once')->assertDontSee(str_repeat('a', 64));
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        $this->actingAs($user)->post($this->url($tenant, $router).'/radius-credential', [
            'nas_identifier' => 'existing-nas', 'confirm_radius_mode' => '1',
        ])->assertStatus(503)->assertDontSee(str_repeat('a', 64));
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        // Explicit recovery is a model-level operation, not a claim that a UI exists.
        $router->forceFill(['access_backend' => 'site_agent'])->save();
        $after = DB::table('routers')->where('id', $router->id)->first();
        $this->assertSame('site_agent', $after->access_backend);
        $this->assertSame($before->radius_shared_secret, $after->radius_shared_secret);
        $this->assertSame($before->radius_nas_identifier, $after->radius_nas_identifier);
    }

    public function test_show_of_unconfigured_router_hides_enable_form_without_changing_state(): void
    {
        [$tenant, $router, $user] = $this->fixture('show-block');
        $before = DB::table('routers')->where('id', $router->id)->first();
        $this->actingAs($user)->get($this->url($tenant, $router))->assertOk()
            ->assertSee('RADIUS access unavailable')->assertSee('Script and direct deployment blocked')
            ->assertDontSee('Enable RADIUS and show secret once');
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
    }

    public function test_crafted_router_update_cannot_mass_assign_radius_fields(): void
    {
        [$tenant, $router, $user] = $this->fixture('update-block');
        $this->actingAs($user)->put($this->url($tenant, $router), [
            'name' => 'Synthetic router', 'api_port' => 8728,
            'access_backend' => 'radius', 'radius_nas_identifier' => 'injected-nas',
            'radius_shared_secret' => 'injected-secret',
        ])->assertRedirect();
        $fresh = Router::withoutTenancy()->findOrFail($router->id);
        $this->assertSame('site_agent', $fresh->access_backend);
        $this->assertNull($fresh->radius_nas_identifier);
        $this->assertNull($fresh->getRawOriginal('radius_shared_secret'));
    }

    public function test_authorization_and_tenant_isolation_still_apply(): void
    {
        [$tenant, $router] = $this->fixture('other-owner');
        [$other, , $admin] = $this->fixture('other-admin');
        $network = User::factory()->create(['tenant_id' => $tenant->id]);
        $network->assignRole('operator_network');
        $payload = ['nas_identifier' => 'synthetic-nas', 'confirm_radius_mode' => '1'];
        $this->actingAs($network)->post($this->url($tenant, $router).'/radius-credential', $payload)->assertForbidden();
        $this->actingAs($admin)->post($this->url($other, $router).'/radius-credential', $payload)->assertNotFound();
        $this->assertNull(DB::table('routers')->where('id', $router->id)->value('radius_shared_secret'));
    }

    private function fixture(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'Synthetic router']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        return [$tenant, $router, $user];
    }

    private function url(Tenant $tenant, Router $router): string
    {
        return 'http://'.$tenant->slug.'.localhost:8000/operator/routers/'.$router->id;
    }
}
