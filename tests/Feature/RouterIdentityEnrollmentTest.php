<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterIdentityEnrollmentService;
use App\Services\RouterIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RouterIdentityEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_identity_must_be_operator_enrolled_and_cannot_be_replaced(): void
    {
        $tenant = Tenant::create([
            'name' => 'Identity Test',
            'slug' => 'identity-test',
            'status' => 'active',
        ]);
        $router = Router::withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lab Router',
        ]);
        $detectedIdentity = [
            'hardware_model' => 'CHR',
            'serial_number' => 'TEST-CHR-123',
        ];

        $enrolled = app(RouterIdentityEnrollmentService::class)->enroll($router, $detectedIdentity);

        $this->assertSame('CHR', $enrolled->hardware_model);
        $this->assertSame('TEST-CHR-123', $enrolled->serial_number);
        $this->assertNotNull($enrolled->identity_verified_at);
        $this->assertSame('verified', app(RouterIdentityService::class)->status($enrolled, $detectedIdentity));

        try {
            app(RouterIdentityEnrollmentService::class)->enroll($enrolled, [
                'hardware_model' => 'Different Router',
                'serial_number' => 'DIFFERENT-SERIAL',
            ]);
            $this->fail('An enrolled identity must not be replaceable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be replaced', $exception->getMessage());
        }

        $this->assertSame('CHR', $enrolled->fresh()->hardware_model);
        $this->assertSame('TEST-CHR-123', $enrolled->fresh()->serial_number);
    }

    public function test_identity_match_requires_both_model_and_serial(): void
    {
        $service = app(RouterIdentityService::class);
        $expected = [
            'verified' => true,
            'hardware_model' => 'CHR',
            'serial_number' => 'TEST-CHR-123',
        ];

        $this->assertSame('unverified', $service->statusForExpected([
            'hardware_model' => 'CHR',
            'serial_number' => 'TEST-CHR-123',
        ], null));
        $this->assertSame('verified', $service->statusForExpected([
            'hardware_model' => ' chr ',
            'serial_number' => 'test-chr-123',
        ], $expected));
        $this->assertSame('mismatch', $service->statusForExpected([
            'hardware_model' => 'CHR',
            'serial_number' => '',
        ], $expected));
    }
}
