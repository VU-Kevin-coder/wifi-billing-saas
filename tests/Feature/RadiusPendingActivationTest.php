<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RadiusAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RadiusPendingActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_genuine_access_accept_activates_pending_radius_and_clears_the_reject_streak(): void
    {
        config(['radius.enabled' => true]);
        $router = $this->makePendingRouter();
        $router->forceFill(['radius_access_reject_streak' => 2])->saveOrFail();

        app(RadiusAccountingService::class)->recordPostAuthEvent($this->event($router, 'accept'));

        $router->refresh();
        $this->assertSame('radius', $router->access_backend);
        $this->assertFalse($router->pending_radius_activation);
        $this->assertSame(0, $router->radius_access_reject_streak);
        $this->assertNull($router->radius_activation_paused_at);
    }

    public function test_three_consecutive_rejects_pause_activation_and_preserve_the_existing_backend(): void
    {
        config(['radius.enabled' => true]);
        $router = $this->makePendingRouter();
        $accounting = app(RadiusAccountingService::class);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $accounting->recordPostAuthEvent($this->event($router, 'reject'));
        }

        $router->refresh();
        $this->assertSame('site_agent', $router->access_backend);
        $this->assertTrue($router->pending_radius_activation);
        $this->assertSame(3, $router->radius_access_reject_streak);
        $this->assertNotNull($router->radius_activation_paused_at);
        $this->assertNull($accounting->routerBoundToPeer(
            (int) $router->id,
            '10.8.1.20',
            (string) $router->radius_nas_identifier,
        ));
    }

    private function makePendingRouter(): Router
    {
        $tenant = Tenant::create([
            'name' => 'RADIUS Activation Test',
            'slug' => 'radius-activation-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pending Router',
            'status' => 'online',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
        ]);
        $router->forceFill([
            'access_backend' => 'site_agent',
            'pending_radius_activation' => true,
            'radius_nas_identifier' => 'router-'.$router->id,
            'radius_shared_secret' => str_repeat('b', 64),
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'RADIUS activation peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);

        return $router->fresh();
    }

    private function event(Router $router, string $outcome): array
    {
        return [
            'authenticated_router_id' => (int) $router->id,
            'authenticated_peer_ip' => '10.8.1.20',
            'nas_identifier' => (string) $router->radius_nas_identifier,
            'outcome' => $outcome,
        ];
    }
}
