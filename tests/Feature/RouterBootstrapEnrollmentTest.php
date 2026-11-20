<?php

namespace Tests\Feature;

use App\Jobs\VerifyRadiusReadinessJob;
use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RouterApiCertificateTrustService;
use App\Services\RouterOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterBootstrapEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_routeros_command_fetches_and_imports_the_short_lived_script_with_verified_https(): void
    {
        config(['app.url' => 'https://billing.example.test']);

        $command = app(RouterOnboardingService::class)->bootstrapCommand(str_repeat('A', 64));

        $this->assertStringStartsWith('/tool fetch url="https://billing.example.test/api/provision/bootstrap?token=', $command);
        $this->assertStringContainsString('mode=https check-certificate=yes', $command);
        $this->assertStringContainsString('/import file-name="wifi-billing-bootstrap.rsc"', $command);
        $this->assertStringContainsString('/file remove "wifi-billing-bootstrap.rsc"', $command);
        $this->assertStringNotContainsString('check-certificate=no', $command);
    }

    public function test_bootstrap_returns_plain_text_and_successful_enrollment_burns_token_without_switching_backend(): void
    {
        Queue::fake();
        $token = Str::random(64);
        [$router, $onboarding] = $this->makeOnboarding($token);
        $this->mock(RouterApiCertificateTrustService::class, function ($mock) use ($router): void {
            $mock->shouldReceive('store')
                ->once()
                ->withArgs(fn (Router $target, string $certificate): bool => $target->id === $router->id
                    && $certificate === 'test-certificate')
                ->andReturn(storage_path('app/router-ca/test-router.pem'));
        });

        $bootstrap = $this->get(route('api.provision.bootstrap', ['token' => $token]));
        $bootstrap
            ->assertOk()
            ->assertSeeText('bootstrap-script', false);
        $this->assertStringContainsString('no-store', (string) $bootstrap->headers->get('Cache-Control'));
        $this->assertSame(1, $onboarding->fresh()->bootstrap_fetches);

        $payload = [
            'token' => $token,
            'serial' => 'CHR-SYSTEM-1',
            'model' => 'MikroTik CHR',
            'api_ca_certificate' => 'test-certificate',
        ];
        $this->postJson(route('api.provision.enroll'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'enrolled');

        $router->refresh();
        $onboarding->refresh();
        $this->assertTrue($router->enrolled);
        $this->assertSame('CHR-SYSTEM-1', $router->serial_number);
        $this->assertSame('MikroTik CHR', $router->hardware_model);
        $this->assertSame('site_agent', $router->access_backend);
        $this->assertTrue($router->pending_radius_activation);
        $this->assertSame('connected', $onboarding->status);
        $this->assertNull($onboarding->bootstrap_token_hash);
        $this->assertNull($onboarding->bootstrap_script);
        Queue::assertPushed(VerifyRadiusReadinessJob::class);

        $this->postJson(route('api.provision.enroll'), $payload)->assertUnprocessable();
        $this->get(route('api.provision.bootstrap', ['token' => $token]))->assertNotFound();
    }

    public function test_bootstrap_rejects_expired_tokens(): void
    {
        $token = Str::random(64);
        [$router] = $this->makeOnboarding($token, now()->subMinute());

        $this->get(route('api.provision.bootstrap', ['token' => $token]))->assertNotFound();
        $this->assertSame('provisioning', $router->fresh()->status);
    }

    /**
     * @return array{Router, RouterOnboarding}
     */
    private function makeOnboarding(string $token, ?\Illuminate\Support\Carbon $expiresAt = null): array
    {
        $tenant = Tenant::create([
            'name' => 'Bootstrap Test',
            'slug' => 'bootstrap-test-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'CHR Bootstrap',
            'status' => 'provisioning',
            'api_username' => 'billing_api',
            'api_password' => 'private-test-password',
            'api_port' => 8729,
        ]);
        $router->forceFill([
            'access_backend' => 'site_agent',
            'pending_radius_activation' => true,
            'radius_nas_identifier' => 'router-'.$router->id,
            'radius_shared_secret' => str_repeat('a', 64),
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Bootstrap peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);
        $onboarding = RouterOnboarding::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', $token),
            'bootstrap_script' => '/system identity print # bootstrap-script',
            'bootstrap_command' => 'command',
            'status' => 'awaiting_bootstrap',
            'expires_at' => $expiresAt ?? now()->addMinutes(30),
        ]);

        return [$router->fresh(), $onboarding];
    }
}
