<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VpnPeer;
use App\Services\RadiusRouterCredentialService;
use App\Services\WireGuardCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RouterOnboardingReissueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
    }

    public function test_reissue_rotates_the_peer_and_invalidates_the_old_bootstrap_token(): void
    {
        config([
            'app.url' => 'https://billing.example.test',
            'wireguard.enabled' => true,
            'wireguard.interface' => 'wg0',
            'wireguard.server_address' => '10.8.0.1',
            'wireguard.endpoint' => '198.51.100.10',
            'wireguard.listen_port' => 51820,
            'wireguard.keepalive' => 25,
            'wireguard.router_interface' => 'wifi-billing',
        ]);
        [$tenant, $router, $user, $oldPeer, $onboarding, $oldToken] = $this->makePendingRouter();

        $serverPublicKey = base64_encode(str_repeat('s', 32));
        $newPrivateKey = base64_encode(str_repeat('n', 32));
        $newPublicKey = base64_encode(str_repeat('p', 32));
        $runner = Mockery::mock(WireGuardCommandRunner::class);
        $runner->shouldReceive('run')->once()
            ->with(['wg', 'set', 'wg0', 'peer', $oldPeer->public_key, 'remove'])
            ->andReturn('');
        $runner->shouldReceive('run')->twice()
            ->with(['wg', 'show', 'wg0', 'public-key'])
            ->andReturn($serverPublicKey);
        $runner->shouldReceive('run')->once()
            ->with(['wg', 'genkey'])
            ->andReturn($newPrivateKey);
        $runner->shouldReceive('run')->once()
            ->with(['wg', 'pubkey'], $newPrivateKey.PHP_EOL)
            ->andReturn($newPublicKey);
        $runner->shouldReceive('run')->once()
            ->with(['wg', 'set', 'wg0', 'peer', $newPublicKey, 'allowed-ips', '10.8.1.2/32'])
            ->andReturn('');
        $this->app->instance(WireGuardCommandRunner::class, $runner);

        $radiusCredentials = Mockery::mock(RadiusRouterCredentialService::class);
        $radiusCredentials->shouldReceive('preparePendingActivation')
            ->once()
            ->andReturn(str_repeat('a', 64));
        $this->app->instance(RadiusRouterCredentialService::class, $radiusCredentials);

        $url = route('operator.routers.onboarding.reissue', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]);
        $response = $this->actingAs($user)->post($url, ['confirm_router_reset' => '1']);

        $response->assertRedirect(route('operator.routers.onboarding', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]));
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('vpn_peers', ['id' => $oldPeer->id]);

        $newPeer = VpnPeer::query()->where('router_id', $router->id)->firstOrFail();
        $this->assertSame($newPublicKey, $newPeer->public_key);
        $this->assertNull($newPeer->private_key);

        $freshOnboarding = $onboarding->fresh();
        $this->assertSame('awaiting_bootstrap', $freshOnboarding->status);
        $this->assertNotSame(hash('sha256', $oldToken), $freshOnboarding->bootstrap_token_hash);
        $this->assertNotSame(hash('sha256', 'old-polling-token'), $router->fresh()->polling_token_hash);

        $newToken = null;
        if (preg_match('/token=([A-Za-z0-9]+)/', $freshOnboarding->bootstrap_command, $matches)) {
            $newToken = $matches[1];
        }
        $this->assertNotNull($newToken);
        $this->actingAs($user)
            ->get(route('operator.routers.onboarding', [
                'tenant' => $tenant->slug,
                'router' => $router->id,
            ]))
            ->assertOk()
            ->assertSee($newToken)
            ->assertSee('Issue a fresh onboarding command');

        $onboardingService = app(\App\Services\RouterOnboardingService::class);
        $this->assertNull($onboardingService->fetchBootstrapScript($oldToken));
        $newScript = $onboardingService->fetchBootstrapScript($newToken);
        $this->assertIsString($newScript);
        $this->assertStringContainsString($newPrivateKey, $newScript);
    }

    public function test_reissue_requires_explicit_router_reset_confirmation(): void
    {
        [$tenant, $router, $user, , $onboarding] = $this->makePendingRouter();

        $response = $this->actingAs($user)->from('/')->post(route('operator.routers.onboarding.reissue', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]));

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('confirm_router_reset');
        $this->assertSame('awaiting_bootstrap', $onboarding->fresh()->status);
        $this->assertNotNull($onboarding->fresh()->bootstrap_token_hash);
    }

    public function test_enrolled_router_cannot_be_reissued(): void
    {
        [$tenant, $router, $user, , $onboarding] = $this->makePendingRouter();
        $router->forceFill(['enrolled' => true])->saveOrFail();

        $response = $this->actingAs($user)->from('/')->post(route('operator.routers.onboarding.reissue', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]), ['confirm_router_reset' => '1']);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('onboarding');
        $this->assertSame('awaiting_bootstrap', $onboarding->fresh()->status);
    }

    public function test_router_with_active_sessions_cannot_be_reissued(): void
    {
        [$tenant, $router, $user, , $onboarding] = $this->makePendingRouter();
        Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->from('/')->post(route('operator.routers.onboarding.reissue', [
            'tenant' => $tenant->slug,
            'router' => $router->id,
        ]), ['confirm_router_reset' => '1']);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('onboarding');
        $this->assertSame('awaiting_bootstrap', $onboarding->fresh()->status);
    }

    private function makePendingRouter(): array
    {
        $tenant = Tenant::create([
            'name' => 'Reissue Test',
            'slug' => 'reissue-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'vpn_subnet' => '10.8.1.0/24',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');

        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Router Reissue Test',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
            'pending_radius_activation' => true,
        ]);
        $oldPeer = VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => $router->name,
            'public_key' => base64_encode(str_repeat('o', 32)),
            'private_key' => base64_encode(str_repeat('k', 32)),
            'tunnel_ip' => '10.8.1.2',
            'enabled' => true,
        ]);

        $oldToken = Str::random(64);
        $onboarding = RouterOnboarding::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', $oldToken),
            'bootstrap_script' => 'old bootstrap script',
            'bootstrap_command' => 'old bootstrap command',
            'status' => 'awaiting_bootstrap',
            'expires_at' => now()->addMinutes(30),
        ]);
        $router->forceFill([
            'polling_token_hash' => hash('sha256', 'old-polling-token'),
        ])->saveOrFail();

        return [$tenant, $router, $user, $oldPeer, $onboarding, $oldToken];
    }
}
