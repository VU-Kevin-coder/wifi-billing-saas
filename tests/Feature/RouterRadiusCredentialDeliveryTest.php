<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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

    public function test_authorized_operator_receives_encrypted_router_secret_once_without_cache_or_audit_disclosure(): void
    {
        $tenant = $this->tenant('radius-owner');
        $router = $this->router($tenant);
        $operator = $this->operator($tenant);
        $serviceSecret = 'separate-radius-service-credential-for-test';
        config(['radius_policy.service_secret' => $serviceSecret]);

        $response = $this->actingAs($operator)->post($this->provisionUrl($tenant, $router), [
            'nas_identifier' => 'nas-radius-owner-01',
            'confirm_radius_mode' => '1',
        ]);

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('This secret is shown once.')
            ->assertDontSee($serviceSecret);

        $matches = [];
        $this->assertSame(
            1,
            preg_match('/<code id="radius-shared-secret"[^>]*>([a-f0-9]{64})<\/code>/', $response->getContent(), $matches),
            'The protected one-time page should contain exactly one generated router secret.'
        );
        $secret = $matches[1];
        $this->assertSame(1, substr_count($response->getContent(), $secret));

        $ciphertext = DB::table('routers')->where('id', $router->id)->value('radius_shared_secret');
        $this->assertNotSame($secret, $ciphertext);
        $this->assertSame($secret, Crypt::decryptString($ciphertext));

        $freshRouter = $router->fresh();
        $this->assertSame('radius', $freshRouter->access_backend);
        $this->assertSame('nas-radius-owner-01', $freshRouter->radius_nas_identifier);
        $this->assertArrayNotHasKey('radius_shared_secret', $freshRouter->toArray());

        $audit = AuditLog::query()->where('action', 'router.radius_credential_provisioned')->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($audit->meta, JSON_THROW_ON_ERROR));

        $repeat = $this->actingAs($operator)->post($this->provisionUrl($tenant, $router), [
            'nas_identifier' => 'nas-radius-owner-01',
            'confirm_radius_mode' => '1',
        ]);
        $repeat->assertStatus(409)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertDontSee($secret);
        $this->assertSame($ciphertext, DB::table('routers')->where('id', $router->id)->value('radius_shared_secret'));
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

    public function test_failed_persistence_rolls_back_mode_and_never_returns_a_secret(): void
    {
        $tenant = $this->tenant('radius-rollback');
        $router = $this->router($tenant);
        $operator = $this->operator($tenant);

        Router::getEventDispatcher()->listen('eloquent.saving: '.Router::class, function (Router $savingRouter) use ($router): ?bool {
            if ($savingRouter->is($router) && $savingRouter->access_backend === 'radius') {
                return false;
            }

            return null;
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($operator)->post($this->provisionUrl($tenant, $router), [
                'nas_identifier' => 'nas-radius-rollback',
                'confirm_radius_mode' => '1',
            ]);
            $this->fail('A cancelled persistence attempt must not produce a response containing the secret.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The RADIUS credential could not be persisted.', $exception->getMessage());
        }

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
