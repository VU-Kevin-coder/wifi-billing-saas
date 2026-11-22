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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterCommandPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_polling_requires_its_bearer_token_and_leases_a_safe_command(): void
    {
        [$router, $token] = $this->makeRouter();
        $session = $this->makePendingSession($router);
        $command = RouterCommand::create([
            'command_id' => (string) Str::uuid(),
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'target_service' => 'hotspot',
            'target_username' => 'VOUCHER-123',
            'idempotency_key' => 'manual:'.$session->id,
            'state' => 'pending_poll',
            'available_at' => now(),
        ]);
        $heartbeat = ['cpu_usage' => 12, 'memory_usage' => 34, 'active_users' => 2];

        $this->postJson('/api/router/commands/poll', $heartbeat)->assertUnauthorized();

        $response = $this->withToken($token)->post('/api/router/commands/poll', $heartbeat)
            ->assertOk();
        $parts = explode('|', trim($response->getContent()));

        $this->assertCount(5, $parts);
        $this->assertSame($command->command_id, $parts[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $parts[1]);
        $this->assertSame('kick', $parts[2]);
        $this->assertSame('hotspot', $parts[3]);
        $this->assertSame('VOUCHER-123', $parts[4]);
        $this->assertSame(12, $router->fresh()->cpu_usage);
        $this->assertSame(34, $router->fresh()->memory_usage);
        $this->assertSame(2, $router->fresh()->active_users);
        $this->assertSame('online', $router->fresh()->status);
        $this->assertNotNull($router->fresh()->polling_last_seen_at);

        $this->withToken($token)->postJson('/api/router/commands/ack', [
            'command_id' => $parts[0],
            'lease_token' => $parts[1],
            'result_code' => 'kicked',
        ])
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $this->assertSame('delivered', $command->fresh()->state);
        $this->assertTrue($session->fresh()->pending_revocation);
    }

    public function test_expired_poll_lease_can_be_reclaimed_but_stale_lease_cannot_acknowledge(): void
    {
        [$router, $token] = $this->makeRouter();
        $session = $this->makePendingSession($router);
        $command = RouterCommand::create([
            'command_id' => (string) Str::uuid(),
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'target_service' => 'hotspot',
            'target_username' => 'VOUCHER-123',
            'idempotency_key' => 'retry:'.$session->id,
            'state' => 'pending_poll',
            'available_at' => now(),
        ]);

        $firstLease = $this->poll($token);
        $this->travel(121)->seconds();
        $secondLease = $this->poll($token);

        $this->assertSame($command->command_id, $firstLease[0]);
        $this->assertSame($command->command_id, $secondLease[0]);
        $this->assertNotSame($firstLease[1], $secondLease[1]);

        $this->withToken($token)->postJson('/api/router/commands/ack', [
            'command_id' => $firstLease[0],
            'lease_token' => $firstLease[1],
            'result_code' => 'kicked',
        ])
            ->assertStatus(409);

        $this->withToken($token)->postJson('/api/router/commands/ack', [
            'command_id' => $secondLease[0],
            'lease_token' => $secondLease[1],
            'result_code' => 'already_absent',
        ])
            ->assertOk();

        $this->assertSame('delivered', $command->fresh()->state);
    }

    public function test_polling_does_not_mark_an_incomplete_onboarding_router_online(): void
    {
        [$router, $token] = $this->makeRouter();
        RouterOnboarding::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', random_bytes(32)),
            'status' => 'enrolled',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->withToken($token)->postJson('/api/router/commands/poll', [
            'cpu_usage' => 12,
            'memory_usage' => 34,
            'active_users' => 2,
        ])->assertOk();

        $this->assertSame('provisioning', $router->fresh()->status);
    }

    private function poll(string $token): array
    {
        $response = $this->withToken($token)->post('/api/router/commands/poll', [
            'cpu_usage' => 1,
            'memory_usage' => 2,
            'active_users' => 1,
        ])->assertOk();

        return explode('|', trim($response->getContent()));
    }

    private function makeRouter(): array
    {
        $tenant = Tenant::create([
            'name' => 'Polling Test',
            'slug' => 'polling-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $token = bin2hex(random_bytes(32));
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Polling Router',
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
            'name' => 'Polling peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.20',
            'enabled' => true,
        ]);

        return [$router->fresh(), $token];
    }

    private function makePendingSession(Router $router): Session
    {
        $package = Package::create([
            'tenant_id' => $router->tenant_id,
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
            'tenant_id' => $router->tenant_id,
            'package_id' => $package->id,
            'batch_code' => 'POLLING-BATCH',
            'quantity' => 1,
            'status' => 'active',
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $router->tenant_id,
            'package_id' => $package->id,
            'voucher_batch_id' => $batch->id,
            'code' => 'VOUCHER-123',
            'status' => 'redeemed',
        ]);

        return Session::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'voucher_id' => $voucher->id,
            'package_id' => $package->id,
            'status' => 'disconnecting',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'pending_revocation' => true,
            'pending_revocation_status' => 'disconnected',
        ]);
    }
}
