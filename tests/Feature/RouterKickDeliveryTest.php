<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\RouterOnboarding;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VpnPeer;
use App\Services\RouterCommandService;
use App\Services\RouterConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RouterKickDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_kick_uses_api_ssl_first_and_records_delivery_without_clearing_revocation(): void
    {
        [$router, $session] = $this->makeRouterAndSession();
        $this->assertSame('radius', $router->access_backend);
        $this->assertTrue($session->pending_revocation);
        $this->assertSame('VOUCHER-123', Voucher::withoutTenancy()->find($session->voucher_id)?->code);
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->with(Mockery::on(
            fn (Router $candidate): bool => $candidate->is($router),
        ))->andReturn(['ok' => true]);
        $connection->shouldReceive('disconnectUser')
            ->once()
            ->with(Mockery::on(fn (Router $candidate): bool => $candidate->is($router)), 'VOUCHER-123', 'hotspot')
            ->andReturnTrue();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterCommandService::class)->requestKick(
            $session,
            'manual:'.$session->id,
        );
        $this->assertTrue($result, json_encode(RouterCommand::query()->first()?->getAttributes()));

        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'state' => 'delivered',
            'delivered_via' => 'api_ssl',
        ]);
        $this->assertTrue($session->fresh()->pending_revocation);
    }

    public function test_api_ssl_failure_leaves_an_idempotent_router_polling_fallback(): void
    {
        [$router, $session] = $this->makeRouterAndSession();
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection failed.',
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        $service = app(RouterCommandService::class);
        $result = $service->requestKick($session, 'manual:'.$session->id);
        $this->assertTrue($result, json_encode(RouterCommand::query()->first()?->getAttributes()));
        $this->assertTrue($service->requestKick($session, 'manual:'.$session->id));

        $this->assertSame(1, RouterCommand::query()->count());
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'state' => 'pending_poll',
            'delivered_via' => null,
        ]);
    }

    private function makeRouterAndSession(): array
    {
        $tenant = Tenant::create([
            'name' => 'Kick Test',
            'slug' => 'kick-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $token = bin2hex(random_bytes(32));
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kick Router',
            'status' => 'online',
            'api_username' => 'billing_api',
            'api_password' => 'test-password',
        ]);
        $router->forceFill([
            'access_backend' => 'radius',
            'enrolled' => true,
            'radius_nas_identifier' => 'router-'.$router->id,
            'radius_shared_secret' => str_repeat('a', 64),
            'polling_token_hash' => hash('sha256', $token),
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'Kick peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);
        RouterOnboarding::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', $token),
            'bootstrap_script' => '/system identity print',
            'bootstrap_command' => 'bootstrap',
            'status' => 'api_verified',
            'expires_at' => now()->addMinutes(30),
        ]);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hourly',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'is_published' => true,
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'KICK-BATCH',
            'quantity' => 1,
            'status' => 'active',
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'voucher_batch_id' => $batch->id,
            'code' => 'VOUCHER-123',
            'status' => 'redeemed',
        ]);
        $session = Session::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'voucher_id' => $voucher->id,
            'package_id' => $package->id,
            'status' => 'disconnecting',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'pending_revocation' => true,
            'pending_revocation_status' => 'disconnected',
        ]);

        return [$router->fresh(), $session->fresh()];
    }
}
