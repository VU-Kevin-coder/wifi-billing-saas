<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterIdentityEnrollmentService;
use App\Services\RouterIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RouterIdentityUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $this->tenant = Tenant::create([
            'name' => 'Identity Lab',
            'slug' => 'identity-lab',
            'status' => 'active',
        ]);
        $this->operator = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'identity-admin@example.test',
        ]);
        $this->operator->assignRole('operator_admin');

        $site = Site::create(['tenant_id' => $this->tenant->id, 'name' => 'CHR Lab']);
        $this->router = Router::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'name' => 'CHR-Test',
            'management_ip' => 'chr.local',
            'api_username' => 'admin',
            'api_password' => 'admin',
            'api_port' => 8729,
            'status' => 'offline',
        ]);
    }

    public function test_operator_can_discover_identity_then_confirm_enrollment(): void
    {
        $this->mock(RouterIdentityService::class, function ($mock) {
            $mock->shouldReceive('discover')->once()->andReturn([
                'hardware_model' => 'CHR',
                'serial_number' => 'CHR-SERIAL-1',
                'routeros_version' => '7.24.6',
                'identity_state' => 'not_enrolled',
            ]);
        });

        $this->mock(RouterIdentityEnrollmentService::class, function ($mock) {
            $mock->shouldReceive('enroll')->once()->andReturn([
                'hardware_model' => 'CHR',
                'serial_number' => 'CHR-SERIAL-1',
                'routeros_version' => '7.24.6',
                'identity_state' => 'verified',
            ]);
        });

        $this->actingAs($this->operator);

        $parameters = ['tenant' => $this->tenant->slug, 'router' => $this->router->id];
        $this->postJson(route('operator.routers.identity.discover', $parameters))
            ->assertOk()
            ->assertJsonPath('identity.serial_number', 'CHR-SERIAL-1');

        $this->postJson(route('operator.routers.identity.enroll', $parameters), [
            'confirm_identity_enrollment' => '1',
        ])->assertOk()
            ->assertJsonPath('identity.identity_state', 'verified');
    }

    public function test_router_detail_displays_identity_lock_controls(): void
    {
        $this->actingAs($this->operator);

        $response = $this->get(route('operator.routers.show', [
            'tenant' => $this->tenant->slug,
            'router' => $this->router->id,
        ]));

        $response->assertOk()
            ->assertSee('Router identity lock')
            ->assertSee('Discover identity (read-only)')
            ->assertSee('Enrollment required');
    }

    public function test_enrollment_requires_explicit_confirmation(): void
    {
        $this->actingAs($this->operator);

        $this->postJson(route('operator.routers.identity.enroll', [
            'tenant' => $this->tenant->slug,
            'router' => $this->router->id,
        ]))->assertStatus(422);

        $this->assertNull($this->router->fresh()->identity_verified_at);
    }
}
