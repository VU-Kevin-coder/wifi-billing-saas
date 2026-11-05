<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\RouterConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_session_is_disconnected_and_marked_expired(): void
    {
        config(['site_agent.required' => false]);
        [$router, $voucher, $session] = $this->createExpiredSession();

        $this->mock(RouterConnectionService::class, function ($mock) use ($router, $voucher): void {
            $mock->shouldReceive('disconnectUser')
                ->once()
                ->withArgs(fn (Router $target, string $code): bool => $target->is($router) && $code === $voucher->code)
                ->andReturnTrue();
        });

        $this->artisan('sessions:expire')->assertSuccessful();

        $this->assertSame('expired', Session::withoutTenancy()->findOrFail($session->id)->status);
    }

    public function test_failed_router_disconnect_keeps_expired_session_pending_for_retry(): void
    {
        config(['site_agent.required' => false]);
        [$router, $voucher, $session] = $this->createExpiredSession();

        $this->mock(RouterConnectionService::class, function ($mock) use ($router, $voucher): void {
            $mock->shouldReceive('disconnectUser')
                ->once()
                ->withArgs(fn (Router $target, string $code): bool => $target->is($router) && $code === $voucher->code)
                ->andThrow(new \RuntimeException('Router unavailable'));
        });

        $this->artisan('sessions:expire')->assertExitCode(1);

        $this->assertSame('active', Session::withoutTenancy()->findOrFail($session->id)->status);
    }

    private function createExpiredSession(): array
    {
        $tenant = Tenant::create(['name' => 'Expiry test', 'slug' => 'expiry-test']);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One minute',
            'price' => 100,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'minutes',
            'speed_limit_mbps' => 5,
            'device_limit' => 1,
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'EXPIRY-TEST',
            'quantity' => 1,
        ]);
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Expiry router',
            'management_ip' => '192.0.2.1',
            'api_username' => 'expiry-user',
            'api_password' => 'expiry-password',
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'EXPIRY-001',
            'status' => 'redeemed',
            'redeemed_at' => now()->subHour(),
        ]);
        $session = Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'voucher_id' => $voucher->id,
            'package_id' => $package->id,
            'status' => 'active',
            'started_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);

        return [$router, $voucher, $session];
    }
}
