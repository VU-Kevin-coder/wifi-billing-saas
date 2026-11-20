<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\WireGuardCommandRunner;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WireGuardOnboardingScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_script_sets_tls_radius_and_enrollment_without_enabling_plaintext_api(): void
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
        $serverPublicKey = base64_encode(random_bytes(32));
        $commandRunner = Mockery::mock(WireGuardCommandRunner::class);
        $commandRunner->shouldReceive('run')
            ->once()
            ->with(['wg', 'show', 'wg0', 'public-key'])
            ->andReturn($serverPublicKey);
        $this->app->instance(WireGuardCommandRunner::class, $commandRunner);

        $tenant = Tenant::create([
            'name' => 'WireGuard Onboarding Test',
            'slug' => 'wireguard-onboarding-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Router Script Test',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
            'enable_hotspot' => true,
        ]);
        $peer = VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Script test peer',
            'public_key' => base64_encode(random_bytes(32)),
            'private_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);
        $router->forceFill(['radius_nas_identifier' => 'router-'.$router->id])->saveOrFail();

        $script = app(WireGuardService::class)->onboardingConfiguration(
            $peer,
            $router->fresh(),
            str_repeat('T', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
        );

        $this->assertStringContainsString('api-ssl certificate="wifi-billing-api" port=8729', $script);
        $this->assertStringContainsString('/ip cloud set update-time=yes', $script);
        $this->assertStringContainsString('subject-alt-name="IP:10.8.1.20"', $script);
        $this->assertStringContainsString('/ip service set api disabled=yes', $script);
        $this->assertStringContainsString('service=hotspot authentication-port=1812 accounting-port=1813', $script);
        $this->assertStringContainsString('use-radius=yes radius-accounting=yes', $script);
        $this->assertStringContainsString('https://billing.example.test/api/provision/enroll', $script);
        $this->assertStringContainsString('check-certificate=yes', $script);
        $this->assertStringContainsString('Authorization: Bearer '.str_repeat('b', 64), $script);
        $this->assertStringContainsString('interval=1m on-event="/system script run wifi-billing-poll"', $script);
        $this->assertStringContainsString('/ip hotspot active remove \\$activeId', $script);
        $this->assertStringContainsString('/ppp active remove \\$activeId', $script);
        $this->assertStringContainsString('targetService = \\"pppoe\\"', $script);
        $this->assertStringNotContainsString(':parse', strtolower($script));
        $this->assertStringNotContainsString('eval', strtolower($script));
        $this->assertStringContainsString('/file get [find name="wifi-billing-api.crt"] contents', $script);
        $this->assertStringNotContainsString('cert_export_wifi-billing-api.crt', $script);
        $this->assertStringNotContainsString('check-certificate=no', $script);
        $this->assertNull($peer->fresh()->private_key);
        $this->assertNotNull($peer->fresh()->config_downloaded_at);
    }
}
