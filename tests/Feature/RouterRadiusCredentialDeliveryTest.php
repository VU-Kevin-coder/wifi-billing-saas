<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use App\Services\RadiusRouterCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RouterRadiusCredentialDeliveryTest extends TestCase
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

    public function test_authorized_operator_is_denied_new_secret_without_cache_or_audit_disclosure(): void
    {
        $tenant = $this->tenant('radius-owner');
        $router = $this->router($tenant);
        $operator = $this->operator($tenant);
        $serviceSecret = 'separate-radius-service-credential-for-test';
        config(['radius_policy.service_secret' => $serviceSecret]);
        $before = DB::table('routers')->where('id', $router->id)->first();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->actingAs($operator)->post($this->provisionUrl($tenant, $router), [
                'nas_identifier' => 'nas-radius-owner-01', 'confirm_radius_mode' => '1',
            ]);
            $response->assertStatus(503)
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
                ->assertHeader('Pragma', 'no-cache')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertSee(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE)
                ->assertDontSee($serviceSecret)
                ->assertDontSee('radius-shared-secret');
            $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
            $this->assertUnconfigured($router);
        }
        $this->assertArrayNotHasKey('radius_shared_secret', $router->fresh()->toArray());
        $this->assertSame(0, AuditLog::query()->where('action', 'router.radius_credential_provisioned')->count());
    }

    public function test_existing_synthetic_secret_stays_encrypted_hidden_and_unchanged_when_opt_in_is_denied(): void
    {
        $tenant = $this->tenant('existing-radius-secret');
        $router = $this->router($tenant);
        $secret = str_repeat('a', 64);
        $router->forceFill(['access_backend' => 'radius', 'radius_nas_identifier' => 'existing-nas', 'radius_shared_secret' => $secret])->save();
        $before = DB::table('routers')->where('id', $router->id)->first();
        $this->assertNotSame($secret, $before->radius_shared_secret);
        $this->assertSame($secret, Crypt::decryptString($before->radius_shared_secret));
        $this->assertArrayNotHasKey('radius_shared_secret', $router->fresh()->toArray());
        $response = $this->actingAs($this->operator($tenant))->post($this->provisionUrl($tenant, $router), [
            'nas_identifier' => 'existing-nas', 'confirm_radius_mode' => '1',
        ]);
        $response->assertStatus(503)->assertSee(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertDontSee($secret);
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        $this->assertSame(0, AuditLog::query()->where('action', 'router.radius_credential_provisioned')->count());
    }

    public function test_synthetic_one_time_view_renders_secret_exactly_once(): void
    {
        $tenant = $this->tenant('synthetic-view');
        $router = $this->router($tenant);
        $secret = str_repeat('b', 64);
        $this->actingAs($this->operator($tenant));
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = view('operator.routers.radius-credential', compact('tenant', 'router', 'secret'))->render();
        $this->assertStringContainsString('This secret is shown once.', $html);
        $this->assertSame(1, preg_match('/<code id="radius-shared-secret"[^>]*>([a-f0-9]{64})<\/code>/', $html, $matches));
        $this->assertSame($secret, $matches[1]);
        $this->assertSame(1, substr_count($html, $secret));
    }

    public function test_non_admin_operator_cannot_provision_a_radius_secret(): void
    {
        $tenant = $this->tenant('radius-role');
        $router = $this->router($tenant);
        $operator = $this->operator($tenant, 'operator_network');

        $this->actingAs($operator)
            ->post($this->provisionUrl($tenant, $router), [
                'nas_identifier' => 'nas-radius-role-01',
                'confirm_radius_mode' => '1',
            ])
            ->assertForbidden();

        $this->assertUnconfigured($router);
    }

    public function test_operator_cannot_provision_a_router_outside_their_tenant(): void
    {
        $operatorTenant = $this->tenant('radius-tenant-a');
        $otherTenant = $this->tenant('radius-tenant-b');
        $router = $this->router($otherTenant);
        $operator = $this->operator($operatorTenant);

        $this->actingAs($operator)
            ->post($this->provisionUrl($operatorTenant, $router), [
                'nas_identifier' => 'nas-cross-tenant',
                'confirm_radius_mode' => '1',
            ])
            ->assertNotFound();

        $this->assertUnconfigured($router);
    }

    public function test_shutdown_rejects_before_persistence_and_never_returns_a_secret(): void
    {
        $tenant = $this->tenant('radius-rollback');
        $router = $this->router($tenant);
        $operator = $this->operator($tenant);
        $before = DB::table('routers')->where('id', $router->id)->first();
        $saveAttempted = false;
        Router::getEventDispatcher()->listen('eloquent.saving: '.Router::class, function (Router $savingRouter) use ($router, &$saveAttempted): ?bool {
            if ($savingRouter->is($router)) {
                $saveAttempted = true;
                return false;
            }
            return null;
        });
        $this->actingAs($operator)->post($this->provisionUrl($tenant, $router), [
            'nas_identifier' => 'nas-radius-rollback', 'confirm_radius_mode' => '1',
        ])->assertStatus(503)->assertSee(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE)
            ->assertDontSee('radius-shared-secret');
        $this->assertFalse($saveAttempted, 'The disabled path must not even attempt persistence.');
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        $this->assertUnconfigured($router);
        $this->assertSame(0, AuditLog::query()->where('action', 'router.radius_credential_provisioned')->count());
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function router(Tenant $tenant): Router
    {
        return Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test router '.$tenant->slug,
        ]);
    }

    private function operator(Tenant $tenant, string $role = 'operator_admin'): User
    {
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole($role);

        return $operator;
    }

    private function provisionUrl(Tenant $tenant, Router $router): string
    {
        return 'http://'.$tenant->slug.'.localhost:8000/operator/routers/'.$router->id.'/radius-credential';
    }

    private function assertUnconfigured(Router $router): void
    {
        $freshRouter = Router::withoutTenancy()->findOrFail($router->id);
        $this->assertSame('site_agent', $freshRouter->access_backend);
        $this->assertNull($freshRouter->radius_nas_identifier);
        $this->assertNull(DB::table('routers')->where('id', $router->id)->value('radius_shared_secret'));
    }
}
