<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterOnboarding;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RouterApiCertificateTrustService;
use App\Services\RouterOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterBootstrapEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_routeros_command_fetches_and_imports_the_short_lived_script_with_verified_https(): void
    {
        config(['app.url' => 'https://billing.example.test']);

        $command = app(RouterOnboardingService::class)->bootstrapCommand(str_repeat('A', 64));

        $this->assertStringStartsWith('/ip cloud set update-time=yes; :delay 10s; /tool fetch url="https://billing.example.test/api/provision/bootstrap?token=', $command);
        $this->assertStringContainsString('mode=https check-certificate=yes', $command);
        $this->assertStringContainsString('/import file-name="wifi-billing-bootstrap.rsc"', $command);
        $this->assertStringContainsString('/file remove "wifi-billing-bootstrap.rsc"', $command);
        $this->assertStringNotContainsString('check-certificate=no', $command);
    }

    public function test_bootstrap_returns_plain_text_and_successful_enrollment_burns_token_without_switching_backend(): void
    {
        $token = Str::random(64);
        [$router, $onboarding] = $this->makeOnboarding($token);
        $certificate = "-----BEGIN CERTIFICATE-----\n".base64_encode('test-router-ca')."\n-----END CERTIFICATE-----";
        $this->mock(RouterApiCertificateTrustService::class, function ($mock) use ($router, $certificate): void {
            $mock->shouldReceive('store')
                ->once()
                ->withArgs(function (Router $target, string $received) use ($router, $certificate): bool {
                    if ($target->id !== $router->id || $received !== $certificate) {
                        return false;
                    }

                    $target->forceFill(['api_ca_certificate' => $received])->saveOrFail();

                    return true;
                });
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
            'api_ca_certificate' => $certificate,
        ];
        $this->postJson(route('api.provision.enroll'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'enrolled');

        $router->refresh();
        $onboarding->refresh();
        $this->assertTrue($router->enrolled);
        $this->assertSame('CHR-SYSTEM-1', $router->serial_number);
        $this->assertSame('MikroTik CHR', $router->hardware_model);
        $this->assertSame($certificate, $router->api_ca_certificate);
        $this->assertSame('site_agent', $router->access_backend);
        $this->assertTrue($router->pending_radius_activation);
        $this->assertSame('enrolled', $onboarding->status);
        $this->assertNotNull($onboarding->enrolled_at);
        $this->assertNull($onboarding->bootstrap_token_hash);
        $this->assertNull($onboarding->bootstrap_script);
        $this->assertArrayNotHasKey('api_ca_certificate', $router->toArray());

        $certificatePath = RouterApiCertificateTrustService::pathForRouter($router->id);
        $this->assertIsString($certificatePath);
        $this->assertFileExists($certificatePath);
        unlink($certificatePath);
        $this->assertSame($certificatePath, RouterApiCertificateTrustService::pathForRouter($router->id));
        $this->assertSame($certificate.PHP_EOL, file_get_contents($certificatePath));
        unlink($certificatePath);

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

    public function test_onboarding_advances_only_through_verified_states(): void
    {
        [, $onboarding] = $this->makeOnboarding(Str::random(64));
        $service = app(RouterOnboardingService::class);

        try {
            $service->markApiVerified($onboarding);
            $this->fail('Onboarding must not skip tunnel verification.');
        } catch (\LogicException $exception) {
            $this->assertSame('Router onboarding cannot skip a verification state.', $exception->getMessage());
        }

        $onboarding->update(['status' => 'enrolled']);
        $this->assertTrue($service->markTunnelVerified($onboarding));
        $this->assertFalse($service->markTunnelVerified($onboarding));
        $this->assertTrue($service->markApiVerified($onboarding));
        $this->assertTrue($service->markRadiusReady($onboarding));
        $service->markCompleted($onboarding);

        $onboarding->refresh();
        $this->assertSame('complete', $onboarding->status);
        $this->assertNotNull($onboarding->tunnel_verified_at);
        $this->assertNotNull($onboarding->api_verified_at);
        $this->assertNotNull($onboarding->radius_ready_at);
        $this->assertNotNull($onboarding->completed_at);
    }

    /**
     * @return array{Router, RouterOnboarding}
     */
    private function makeOnboarding(string $token, ?Carbon $expiresAt = null): array
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
