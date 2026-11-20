<?php

namespace Tests\Feature;

use App\Http\Controllers\Operator\SubscriberController;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterCommand;
use App\Models\RouterOnboarding;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Models\VpnPeer;
use App\Services\RadiusAccountingService;
use App\Services\RouterCommandService;
use App\Services\RouterConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RouterPppoeKickDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pppoe_kick_uses_api_ssl_and_persists_subscriber_session_target(): void
    {
        [$router, $sessionId, $subscriber] = $this->makePppoeRouterSession();
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn(['ok' => true]);
        $connection->shouldReceive('disconnectUser')
            ->once()
            ->with(
                Mockery::on(fn (Router $candidate): bool => $candidate->is($router)),
                $subscriber->username,
                'pppoe',
            )
            ->andReturnTrue();
        $this->app->instance(RouterConnectionService::class, $connection);

        $result = app(RouterCommandService::class)->requestPppoeKick(
            $sessionId,
            'manual:pppoe:'.$sessionId,
        );

        $this->assertTrue($result);
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'session_id' => null,
            'subscriber_session_id' => $sessionId,
            'target_service' => 'pppoe',
            'target_username' => $subscriber->username,
            'state' => 'delivered',
            'delivered_via' => 'api_ssl',
        ]);
    }

    public function test_pppoe_polling_fallback_returns_a_typed_allowlisted_kick(): void
    {
        [$router, $sessionId, $subscriber, $token] = $this->makePppoeRouterSession(true);
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection failed.',
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        $this->assertTrue(app(RouterCommandService::class)->requestPppoeKick(
            $sessionId,
            'suspend:pppoe:'.$sessionId,
        ));

        $response = $this->withToken($token)->post('/api/router/commands/poll', [
            'cpu_usage' => 10,
            'memory_usage' => 20,
            'active_users' => 1,
        ])->assertOk();
        $parts = explode('|', trim($response->getContent()));

        $this->assertCount(5, $parts);
        $this->assertSame('kick', $parts[2]);
        $this->assertSame('pppoe', $parts[3]);
        $this->assertSame($subscriber->username, $parts[4]);
        $this->assertDatabaseHas('router_commands', [
            'subscriber_session_id' => $sessionId,
            'target_service' => 'pppoe',
            'state' => 'poll_leased',
        ]);
        $this->withToken($token)->postJson('/api/router/commands/ack', [
            'command_id' => $parts[0],
            'lease_token' => $parts[1],
            'result_code' => 'kicked',
        ])->assertOk();
        $this->assertSame('delivered', RouterCommand::query()->where('subscriber_session_id', $sessionId)->value('state'));
    }

    public function test_suspended_subscriber_interim_accounting_requests_a_pppoe_kick(): void
    {
        config(['radius.enabled' => true]);
        [$router, $sessionId, $subscriber] = $this->makePppoeRouterSession(true);
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection failed.',
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        app(RadiusAccountingService::class)->recordPppoeAccountingEvent([
            'authenticated_router_id' => $router->id,
            'authenticated_peer_ip' => $router->vpnPeer->tunnel_ip,
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'pppoe-acct-session-1',
            'username' => $subscriber->username,
            'status_type' => 'Interim-Update',
            'event_time' => now()->toIso8601String(),
            'input_octets' => 100,
            'output_octets' => 200,
        ]);

        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'subscriber_session_id' => $sessionId,
            'target_service' => 'pppoe',
            'target_username' => $subscriber->username,
            'state' => 'pending_poll',
        ]);
    }

    public function test_pppoe_interim_at_data_limit_uses_the_polling_fallback(): void
    {
        config(['radius.enabled' => true]);
        [$router, $sessionId, $subscriber] = $this->makePppoeRouterSession(true);
        $subscriber->forceFill(['status' => 'active'])->saveOrFail();
        Package::withoutTenancy()
            ->where('tenant_id', $router->tenant_id)
            ->update(['data_limit' => '1KB']);
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection failed.',
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        app(RadiusAccountingService::class)->recordPppoeAccountingEvent([
            'authenticated_router_id' => $router->id,
            'authenticated_peer_ip' => $router->vpnPeer->tunnel_ip,
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'pppoe-acct-session-1',
            'username' => $subscriber->username,
            'status_type' => 'Interim-Update',
            'event_time' => now()->toIso8601String(),
            'input_octets' => 512,
            'output_octets' => 512,
        ]);

        $this->assertSame(1024, $subscriber->fresh()->bytes_used);
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'subscriber_session_id' => $sessionId,
            'target_service' => 'pppoe',
            'target_username' => $subscriber->username,
            'state' => 'pending_poll',
        ]);
    }

    public function test_suspending_subscriber_queues_kicks_for_open_pppoe_sessions(): void
    {
        [$router, $sessionId, $subscriber] = $this->makePppoeRouterSession(true);
        URL::defaults(['tenant' => $router->tenant->slug]);
        $subscriber->forceFill(['status' => 'active'])->saveOrFail();
        $connection = Mockery::mock(RouterConnectionService::class);
        $connection->shouldReceive('test')->once()->andReturn([
            'ok' => false,
            'message' => 'Connection failed.',
        ]);
        $this->app->instance(RouterConnectionService::class, $connection);

        $response = app(SubscriberController::class)->update(
            Request::create('/operator/subscribers/'.$subscriber->id, 'PATCH', ['status' => 'suspended']),
            $subscriber,
            app(RouterCommandService::class),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'subscriber_session_id' => $sessionId,
            'target_service' => 'pppoe',
            'target_username' => $subscriber->username,
            'state' => 'pending_poll',
        ]);
    }

    private function makePppoeRouterSession(bool $withPollingToken = false): array
    {
        $tenant = Tenant::create([
            'name' => 'PPPoE Kick Tenant',
            'slug' => 'pppoe-kick-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $token = $withPollingToken ? bin2hex(random_bytes(32)) : null;
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPPoE Kick Router',
            'status' => 'online',
            'api_username' => 'billing_api',
            'api_password' => 'test-password',
        ]);
        $router->forceFill([
            'access_backend' => 'radius',
            'enrolled' => true,
            'enable_pppoe' => true,
            'radius_nas_identifier' => 'pppoe-router-'.$router->id,
            'radius_shared_secret' => str_repeat('a', 64),
            'polling_token_hash' => $token ? hash('sha256', $token) : null,
        ])->saveOrFail();
        VpnPeer::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'name' => 'PPPoE peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => '10.8.1.30',
            'enabled' => true,
        ]);
        RouterOnboarding::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'bootstrap_token_hash' => hash('sha256', Str::random(64)),
            'bootstrap_script' => '/system identity print',
            'bootstrap_command' => 'bootstrap',
            'status' => 'api_verified',
            'expires_at' => now()->addMinutes(30),
        ]);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPPoE plan',
            'price' => 1000,
            'duration_value' => 30,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'features' => ['PPPoE'],
        ]);
        $subscriber = Subscriber::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'name' => 'PPPoE subscriber',
            'username' => 'ppp-account-1',
            'password' => 'test-password',
            'status' => 'suspended',
            'bytes_used' => 0,
            'expires_at' => now()->addDays(30),
        ]);
        $sessionId = DB::table('radius_subscriber_sessions')->insertGetId([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'subscriber_id' => $subscriber->id,
            'nas_session_id' => 'pppoe-acct-session-1',
            'generation' => 1,
            'input_bytes' => 0,
            'output_bytes' => 0,
            'state' => 'open',
            'started_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$router->fresh(), $sessionId, $subscriber, $token];
    }
}
