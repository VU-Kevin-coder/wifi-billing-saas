<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RouterOnboardingService;
use App\Services\WireGuardCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RouterPollingCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_embeds_a_unique_polling_token_and_persists_only_its_hash(): void
    {
        config([
            'app.url' => 'https://billing.example.test',
            'radius.enabled' => true,
            'wireguard.enabled' => true,
            'wireguard.interface' => 'wg0',
            'wireguard.server_address' => '10.8.0.1',
            'wireguard.endpoint' => '198.51.100.10',
            'wireguard.listen_port' => 51820,
            'wireguard.keepalive' => 25,
            'wireguard.router_interface' => 'wifi-billing',
        ]);
        $serverPublicKey = base64_encode(random_bytes(32));
        $runner = Mockery::mock(WireGuardCommandRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(['wg', 'show', 'wg0', 'public-key'])
            ->andReturn($serverPublicKey);
        $this->app->instance(WireGuardCommandRunner::class, $runner);

        $tenant = Tenant::create([
            'name' => 'Polling Credential Test',
            'slug' => 'poll-token-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Polling Credential Router',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
        ]);
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Polling credential peer',
            'public_key' => base64_encode(random_bytes(32)),
            'private_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);

        $created = app(RouterOnboardingService::class)->create($router->fresh());
        $router->refresh();
        $script = $created['onboarding']->bootstrap_script;

        $this->assertMatchesRegularExpression('/Authorization: Bearer ([a-f0-9]{64})/', $script);
        preg_match('/Authorization: Bearer ([a-f0-9]{64})/', $script, $matches);
        $this->assertSame(hash('sha256', $matches[1]), $router->polling_token_hash);
        $this->assertArrayNotHasKey('polling_token_hash', $router->toArray());
        $this->assertNotSame($created['onboarding']->bootstrap_token_hash, $router->polling_token_hash);
    }
}
