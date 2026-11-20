<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\RadiusAccountingEvent;
use App\Models\RadiusAccountingSession;
use App\Models\RadiusDisconnectCommand;
use App\Models\RadiusNasSessionIdentity;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Models\VpnPeer;
use App\Services\PaymentService;
use App\Services\RadiusAccountingService;
use App\Services\RadiusRouterCredentialService;
use App\Services\RadiusUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RadiusPolicyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_SECRET = 'independent-test-radius-service-secret-0123456789';

    public function test_router_secret_is_encrypted_and_hidden_from_model_serialization(): void
    {
        $tenant = $this->createTenant('radius-secret');
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'Secret Test Router',
        ]);

        // Legacy synthetic credential: opt-in remains blocked, but encrypted storage
        // and serialization must continue to protect already-configured routers.
        $secret = str_repeat('a', 64);
        $router->forceFill(['access_backend' => 'radius', 'radius_nas_identifier' => 'nas-secret-test', 'radius_shared_secret' => $secret])->save();
        $before = DB::table('routers')->where('id', $router->id)->first();
        try {
            app(RadiusRouterCredentialService::class)->optIn($router, 'nas-secret-test');
            $this->fail('RADIUS opt-in must be blocked');
        } catch (RadiusUnavailableException $e) {
            $this->assertSame(RadiusRouterCredentialService::UNAVAILABLE_MESSAGE, $e->getMessage());
        }
        $this->assertEquals($before, DB::table('routers')->where('id', $router->id)->first());
        $router->refresh();
        $stored = DB::table('routers')->where('id', $router->id)->value('radius_shared_secret');
        $this->assertNotSame($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
        $this->assertSame('radius', $router->access_backend);
        $this->assertSame('nas-secret-test', $router->radius_nas_identifier);
        $this->assertArrayNotHasKey('radius_shared_secret', $router->toArray());
    }

    public function test_private_policy_route_requires_integrity_signature_and_rejects_replayed_nonce(): void
    {
        config([
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => ['10.40.0.0/24'],
        ]);

        $path = '/internal/radius/v1/authorize';
        $payload = [
            'nas_identifier' => 'unknown-nas',
            'nas_session_id' => 'auth-session-id',
            'voucher_code' => 'NOT-A-VOUCHER',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $headers = $this->signatureHeaders('POST', $path, $body, $timestamp, $nonce);

        $server = array_merge(
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.40.0.12'],
            $this->transformHeadersToServerVars($headers),
        );
        $first = $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            $server,
            $body,
        );
        $first->assertStatus(503)->assertExactJson(['message' => 'RADIUS policy unavailable.']);

        $replay = $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            $server,
            $body,
        );
        $replay->assertUnauthorized();

        $tampered = $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            $server,
            json_encode(['nas_identifier' => 'other-nas', 'voucher_code' => 'NOT-A-VOUCHER'], JSON_THROW_ON_ERROR),
        );
        $tampered->assertUnauthorized();
    }

    public function test_private_policy_route_allows_a_signed_request_from_an_allowlisted_private_peer(): void
    {
        config([
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => ['10.41.2.0/24'],
        ]);

        $this->signedCall(
            'POST',
            '/internal/radius/v1/authorize',
            [
                'nas_identifier' => 'unknown-nas',
                'nas_session_id' => 'source-allow-test',
                'voucher_code' => 'NOT-A-VOUCHER',
            ],
            '10.41.2.9',
        )->assertStatus(503)->assertExactJson(['message' => 'RADIUS policy unavailable.']);
    }

    public function test_private_policy_route_rejects_a_non_allowlisted_peer_even_with_forwarded_ip_header(): void
    {
        config([
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => ['10.41.2.0/24'],
        ]);

        $this->signedCall(
            'POST',
            '/internal/radius/v1/authorize',
            [
                'nas_identifier' => 'unknown-nas',
                'nas_session_id' => 'source-deny-test',
                'voucher_code' => 'NOT-A-VOUCHER',
            ],
            '192.168.40.7',
            '10.41.2.9',
        )->assertUnauthorized();
    }

    public function test_signed_policy_authorization_requires_the_router_wireguard_peer_and_returns_safe_attributes(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => [],
        ]);
        [, $router, $voucher] = $this->createRadiusAccess();
        $this->createVpnPeer($router, '10.8.1.20');

        $payload = [
            'authenticated_router_id' => $router->id,
            'authenticated_peer_ip' => '10.8.1.20',
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'router-auth-session',
            'voucher_code' => $voucher->code,
        ];
        $this->signedCall('POST', '/internal/radius/v1/authorize', $payload)
            ->assertOk()
            ->assertJsonPath('control:Cleartext-Password.value.0', $voucher->code)
            ->assertJsonPath('control:Cleartext-Password.do_xlat', false)
            ->assertJsonPath('reply:Port-Limit', 2)
            ->assertJsonPath('reply:Mikrotik-Rate-Limit', '10M/10M')
            ->assertJsonPath('reply:Mikrotik-Total-Limit', 1073741824)
            ->assertJsonPath('reply:Mikrotik-Total-Limit-Gigawords', 1)
            ->assertJsonMissingPath('reply:Auth-Type');

        $payload['authenticated_peer_ip'] = '10.8.1.21';
        $this->signedCall('POST', '/internal/radius/v1/authorize', $payload)->assertUnauthorized();
    }

    public function test_signed_dynamic_client_lookup_returns_only_the_enabled_radius_peer_record(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => [],
        ]);
        [, $router] = $this->createRadiusAccess();
        $peer = $this->createVpnPeer($router, '10.8.1.20');
        $secret = $router->radius_shared_secret;

        $this->signedCall('POST', '/internal/radius/v1/client-lookup', [
            'authenticated_peer_ip' => '10.8.1.20',
            'authenticated_router_id' => 99999,
        ])->assertOk()
            ->assertJsonPath('control:FreeRADIUS-Client-IP-Address.value.0', '10.8.1.20')
            ->assertJsonPath('control:FreeRADIUS-Client-Secret.value.0', $secret)
            ->assertJsonPath('control:FreeRADIUS-Client-Shortname.value.0', 'router-'.$router->id)
            ->assertJsonPath('control:FreeRADIUS-Client-NAS-Type.value.0', 'other')
            ->assertJsonPath('control:FreeRADIUS-Client-Require-MA.value.0', 'yes')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->signedCall('POST', '/internal/radius/v1/client-lookup', [
            'authenticated_peer_ip' => '10.8.1.21',
        ])->assertUnauthorized();

        $peer->update(['enabled' => false]);
        $this->signedCall('POST', '/internal/radius/v1/client-lookup', [
            'authenticated_peer_ip' => '10.8.1.20',
        ])->assertUnauthorized();
    }

    public function test_accounting_endpoint_reconciles_hotspot_sessions_idempotently_through_stop(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.accounting_reconciliation_enabled' => true,
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
            'radius_policy.allowed_source_cidrs' => [],
        ]);
        [, $router, $voucher, $session] = $this->createRadiusAccess();
        $this->createVpnPeer($router, '10.8.1.22');
        $sessionId = 'radius-session-123';
        $uniqueSessionId = 'router-unique-session-123';
        $interimTime = now()->subSeconds(30)->startOfSecond();
        $start = [
            'authenticated_router_id' => $router->id,
            'authenticated_peer_ip' => '10.8.1.22',
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => $sessionId,
            'acct_unique_session_id' => $uniqueSessionId,
            'username' => $voucher->code,
            'status_type' => 'Start',
            'event_time' => now()->subMinute()->format('Y-m-d H:i:s'),
            'session_time' => 0,
            'input_octets' => 100,
            'output_octets' => 200,
        ];
        $this->signedCall('POST', '/internal/radius/v1/accounting-events', $start)->assertNoContent();
        $this->signedCall('POST', '/internal/radius/v1/accounting-events', $start)->assertNoContent();

        $interim = array_merge($start, [
            'status_type' => 'Interim-Update',
            'event_time' => $interimTime->toIso8601String(),
            'session_time' => 30,
            'input_octets' => 400,
            'output_octets' => 800,
        ]);
        $this->signedCall('POST', '/internal/radius/v1/accounting-events', $interim)->assertNoContent();
        $this->signedCall('POST', '/internal/radius/v1/accounting-events', $interim)->assertNoContent();

        $session->forceFill([
            'status' => 'disconnecting',
            'pending_revocation' => true,
            'pending_revocation_status' => 'expired',
        ])->saveOrFail();
        $stop = array_merge($interim, [
            'status_type' => 'Stop',
            'event_time' => now()->format('Y-m-d H:i:s'),
            'session_time' => 60,
            'input_octets' => 900,
            'output_octets' => 1500,
        ]);
        $this->signedCall('POST', '/internal/radius/v1/accounting-events', $stop)->assertNoContent();

        $session->refresh();
        $this->assertSame('expired', $session->status);
        $this->assertSame(900, $session->bytes_in);
        $this->assertSame(1500, $session->bytes_out);
        $this->assertSame(60, $session->session_duration_seconds);
        $this->assertSame($interimTime->timestamp, $session->last_interim_at->timestamp);
        $this->assertFalse($session->pending_revocation);
        $this->assertNotNull($session->accounting_stopped_at);
        $this->assertDatabaseCount('radius_accounting_events', 3);
        $this->assertDatabaseCount('radius_accounting_sessions', 1);
        $this->assertDatabaseHas('radius_accounting_sessions', [
            'identity_id' => DB::table('radius_nas_session_identities')->where('router_id', $router->id)
                ->where('nas_session_id', $sessionId)->value('id'),
            'acct_unique_session_id' => $uniqueSessionId,
            'state' => 'stopped',
        ]);
    }

    public function test_hotspot_generations_aggregate_usage_and_wait_for_all_stops_before_terminal_state(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.accounting_reconciliation_enabled' => true,
        ]);
        [, $router, $voucher, $session] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);
        $base = [
            'nas_identifier' => $router->radius_nas_identifier,
            'voucher_code' => $voucher->code,
        ];
        $firstStart = now()->subSeconds(20)->startOfSecond();
        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-a',
            'acct_unique_session_id' => 'unique-session-a',
            'status_type' => 'Start',
            'event_time' => $firstStart->toIso8601String(),
            'session_time' => 0,
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-a',
            'acct_unique_session_id' => 'unique-session-a',
            'status_type' => 'Interim-Update',
            'event_time' => $firstStart->addSeconds(5)->toIso8601String(),
            'session_time' => 30,
            'input_octets' => 100,
            'output_octets' => 200,
        ]);

        $secondStart = $firstStart->addSecond();
        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-b',
            'acct_unique_session_id' => 'unique-session-b',
            'status_type' => 'Start',
            'event_time' => $secondStart->toIso8601String(),
            'session_time' => 0,
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-b',
            'acct_unique_session_id' => 'unique-session-b',
            'status_type' => 'Interim-Update',
            'event_time' => $secondStart->addSeconds(5)->toIso8601String(),
            'session_time' => 60,
            'input_octets' => 300,
            'output_octets' => 400,
        ]);

        $session->refresh();
        $this->assertSame(400, $session->bytes_in);
        $this->assertSame(600, $session->bytes_out);
        $this->assertSame(90, $session->session_duration_seconds);

        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-a',
            'acct_unique_session_id' => 'unique-session-a',
            'status_type' => 'Stop',
            'event_time' => $firstStart->addSeconds(10)->toIso8601String(),
            'session_time' => 35,
            'input_octets' => 120,
            'output_octets' => 220,
        ]);
        $this->assertSame('active', $session->fresh()->status);
        $this->assertNull($session->fresh()->accounting_stopped_at);

        $service->ingestAccountingEvent($base + [
            'nas_session_id' => 'nas-session-b',
            'acct_unique_session_id' => 'unique-session-b',
            'status_type' => 'Stop',
            'event_time' => $secondStart->addSeconds(10)->toIso8601String(),
            'session_time' => 65,
            'input_octets' => 320,
            'output_octets' => 420,
        ]);
        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->accounting_stopped_at);
        $this->assertSame(440, $session->fresh()->bytes_in);
        $this->assertSame(640, $session->fresh()->bytes_out);
        $this->assertSame(100, $session->fresh()->session_duration_seconds);
    }

    public function test_hotspot_interim_after_expiry_queues_disconnect(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.accounting_reconciliation_enabled' => true,
        ]);
        Queue::fake();
        [, $router, $voucher, $session] = $this->createRadiusAccess();
        $this->createVpnPeer($router, '10.8.1.24');
        $service = app(RadiusAccountingService::class);
        $identity = [
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'expired-time-session',
            'acct_unique_session_id' => 'expired-time-unique',
            'voucher_code' => $voucher->code,
        ];
        $startTime = now()->subMinute();
        $service->ingestAccountingEvent($identity + [
            'status_type' => 'Start',
            'event_time' => $startTime->toIso8601String(),
            'session_time' => 0,
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $session->forceFill(['expires_at' => now()->subSecond()])->saveOrFail();
        $service->ingestAccountingEvent($identity + [
            'status_type' => 'Interim-Update',
            'event_time' => now()->toIso8601String(),
            'session_time' => 60,
            'input_octets' => 10,
            'output_octets' => 10,
        ]);

        $this->assertTrue($session->fresh()->pending_revocation);
        $this->assertSame('expired', $session->fresh()->pending_revocation_status);
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'state' => 'pending_api',
            'target_service' => 'hotspot',
            'target_username' => $voucher->code,
        ]);
    }

    public function test_hotspot_interim_at_data_limit_requests_one_router_kick(): void
    {
        config([
            'radius.enabled' => true,
            'radius_policy.accounting_reconciliation_enabled' => true,
        ]);
        Queue::fake();
        [, $router, $voucher, $session, $package] = $this->createRadiusAccess();
        $this->createVpnPeer($router, '10.8.1.23');
        $package->update(['data_limit' => '1KB']);

        $service = app(RadiusAccountingService::class);
        $identity = [
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'quota-session-1',
            'acct_unique_session_id' => 'quota-unique-1',
            'voucher_code' => $voucher->code,
        ];
        $service->ingestAccountingEvent($identity + [
            'status_type' => 'Start',
            'event_time' => now()->subMinute()->format('Y-m-d H:i:s'),
            'session_time' => 0,
            'input_octets' => 0,
            'output_octets' => 0,
        ]);
        $result = $service->ingestAccountingEvent($identity + [
            'status_type' => 'Interim-Update',
            'event_time' => now()->format('Y-m-d H:i:s'),
            'session_time' => 60,
            'input_octets' => 512,
            'output_octets' => 512,
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertTrue($session->fresh()->pending_revocation);
        $this->assertSame('expired', $session->fresh()->pending_revocation_status);
        $this->assertDatabaseHas('router_commands', [
            'router_id' => $router->id,
            'session_id' => $session->id,
            'operation' => 'kick',
            'state' => 'pending_api',
            'target_service' => 'hotspot',
            'target_username' => $voucher->code,
        ]);
    }

    public function test_production_private_policy_route_keeps_https_hmac_and_replay_checks_for_allowed_peer(): void
    {
        $this->withProductionEnvironment(function (): void {
            config([
                'app.url' => 'https://localhost',
                'radius_policy.service_secret' => self::SERVICE_SECRET,
                'radius_policy.nonce_cache_store' => 'database',
                'radius_policy.allowed_source_cidrs' => ['10.41.3.0/24'],
            ]);
            $this->assertTrue(app()->environment('production'));
            $this->assertSame('database', config('cache.stores.database.driver'));

            $payload = [
                'nas_identifier' => 'unknown-nas',
                'nas_session_id' => 'production-auth-test',
                'voucher_code' => 'NOT-A-VOUCHER',
            ];
            $nonce = Str::random(32);

            $response = $this->signedCall(
                'POST',
                '/internal/radius/v1/authorize',
                $payload,
                '10.41.3.9',
                null,
                $nonce,
            );
            $this->assertSame(503, $response->status(), $response->getContent());
            $response->assertExactJson(['message' => 'RADIUS policy unavailable.']);

            $this->signedCall(
                'POST',
                '/internal/radius/v1/authorize',
                $payload,
                '10.41.3.9',
                null,
                $nonce,
            )->assertUnauthorized();
        });
    }

    public function test_production_private_policy_route_fails_closed_when_source_allowlist_is_missing(): void
    {
        $this->withProductionEnvironment(function (): void {
            config([
                'radius_policy.service_secret' => self::SERVICE_SECRET,
                'radius_policy.nonce_cache_store' => null,
                'radius_policy.allowed_source_cidrs' => [],
            ]);

            $this->signedCall('POST', '/internal/radius/v1/authorize', [
                'nas_identifier' => 'unknown-nas',
                'nas_session_id' => 'missing-allowlist-test',
                'voucher_code' => 'NOT-A-VOUCHER',
            ], '10.42.0.4')->assertUnauthorized();
        });
    }

    public function test_production_private_policy_route_fails_closed_when_source_allowlist_is_invalid(): void
    {
        $this->withProductionEnvironment(function (): void {
            config([
                'radius_policy.service_secret' => self::SERVICE_SECRET,
                'radius_policy.nonce_cache_store' => null,
                'radius_policy.allowed_source_cidrs' => ['10.42.0.0/33'],
            ]);

            $this->signedCall('POST', '/internal/radius/v1/authorize', [
                'nas_identifier' => 'unknown-nas',
                'nas_session_id' => 'invalid-allowlist-test',
                'voucher_code' => 'NOT-A-VOUCHER',
            ], '10.42.0.4')->assertUnauthorized();
        });
    }

    public function test_signed_adapter_claims_cannot_select_a_router_or_mutate_policy_state(): void
    {
        config([
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
        ]);
        [, $routerA, $voucherA, $sessionA] = $this->createRadiusAccess();
        [, $routerB, $voucherB, $sessionB] = $this->createRadiusAccess();

        // A valid adapter signature and a packet-supplied NAS are not proof
        // that either router originated the packet, even with matching voucher.
        foreach ([[$routerA, $voucherA], [$routerB, $voucherB], [$routerB, $voucherA]] as [$claimedRouter, $voucher]) {
            $payload = [
                'nas_identifier' => $claimedRouter->radius_nas_identifier,
                'nas_session_id' => 'attacker-session',
                'voucher_code' => $voucher->code,
                'authenticated_router_id' => $routerA->id,
                'authenticated_nas_identifier' => $routerA->radius_nas_identifier,
            ];
            $this->signedCall('POST', '/internal/radius/v1/authorize', $payload)
                ->assertStatus(503)->assertExactJson(['message' => 'RADIUS policy unavailable.'])
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->signedCall('POST', '/internal/radius/v1/accounting-events', $payload + [
                'status_type' => 'Start',
                'event_time' => now()->toIso8601String(),
            ])->assertStatus(503);
        }

        $this->assertSame('provisioning', $sessionA->fresh()->status);
        $this->assertSame('provisioning', $sessionB->fresh()->status);
        $this->assertSame(0, RadiusAccountingSession::query()->count());
        $this->assertSame(0, RadiusAccountingEvent::query()->count());
        $this->signedCall('GET', '/internal/radius/v1/disconnect-commands')->assertNotFound();
        $this->signedCall('POST', '/internal/radius/v1/disconnect-commands/'.Str::uuid().'/ack', [
            'result' => 'delivered',
        ])->assertNotFound();
        $this->assertSame(0, RadiusDisconnectCommand::query()->count());
    }

    public function test_authorization_rejects_even_matching_router_tenant_and_entitlement_while_unavailable(): void
    {
        [$tenant, $router, $voucher, $session, $package] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);

        $decision = $service->authorize('nas-radius-'.$tenant->slug, 'nas-auth-session', $voucher->code);

        $this->assertSame(['decision' => 'reject'], $decision);
        $this->assertSame('provisioning', $session->fresh()->status);

        $otherTenant = $this->createTenant('radius-other');
        $otherPackage = Package::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other tenant package',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'data_limit' => 'Unlimited',
        ]);
        $otherBatch = VoucherBatch::create([
            'tenant_id' => $otherTenant->id,
            'package_id' => $otherPackage->id,
            'batch_code' => 'OTHER-RADIUS',
            'quantity' => 1,
        ]);
        $otherVoucher = Voucher::create([
            'tenant_id' => $otherTenant->id,
            'voucher_batch_id' => $otherBatch->id,
            'package_id' => $otherPackage->id,
            'code' => 'OTHER-RADIUS-CODE',
            'status' => 'redeemed',
        ]);

        $this->assertSame('reject', $service->authorize('nas-radius-'.$tenant->slug, 'nas-auth-session', $otherVoucher->code)['decision']);
        $this->assertSame('reject', $service->authorize('unknown-nas', 'nas-auth-session', $voucher->code)['decision']);
    }

    public function test_legacy_active_session_without_radius_evidence_is_not_authorized(): void
    {
        [, $router, $voucher, $session] = $this->createRadiusAccess();
        $session->update(['status' => 'active']);

        $decision = app(RadiusAccountingService::class)->authorize(
            $router->radius_nas_identifier,
            'legacy-session-without-accounting',
            $voucher->code,
        );

        $this->assertSame('reject', $decision['decision']);
        $this->assertSame(0, RadiusAccountingSession::query()->count());
    }

    public function test_accounting_replays_updates_and_stops_fail_closed_without_mutation(): void
    {
        [$tenant, $router, $voucher, $session] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);
        $startTime = now()->subSeconds(30)->startOfSecond();
        $start = ['nas_identifier' => $router->radius_nas_identifier, 'nas_session_id' => 'reused-nas-session',
            'status_type' => 'Start', 'event_time' => $startTime->toIso8601String(),
            'input_octets' => 100, 'output_octets' => 200, 'voucher_code' => $voucher->code];
        $interim = array_merge($start, ['status_type' => 'Interim-Update', 'event_time' => $startTime->addMinute()->toIso8601String(), 'input_octets' => 500, 'output_octets' => 700]);
        $stale = array_merge($interim, ['event_time' => $startTime->addSeconds(30)->toIso8601String(), 'input_octets' => 300]);
        $stop = array_merge($interim, ['status_type' => 'Stop', 'event_time' => $startTime->addMinutes(2)->toIso8601String(), 'input_octets' => 800, 'output_octets' => 900]);
        $before = $session->fresh()->getAttributes();
        foreach ([$start, $start, $interim, $stale, $stop, $stop] as $event) {
            $this->assertSame(['accepted' => false, 'quarantined' => true, 'reason' => 'radius_unavailable'], $service->ingestAccountingEvent($event));
            $this->assertSame($before, $session->fresh()->getAttributes());
        }
        $this->assertSame(0, RadiusNasSessionIdentity::query()->count());
        $this->assertSame(0, RadiusAccountingSession::query()->count());
        $this->assertSame(0, RadiusAccountingEvent::query()->count());
        $this->assertSame((int) $tenant->id, (int) $router->fresh()->tenant_id);
    }

    public function test_verified_sale_writes_typed_voucher_link_and_replay_returns_same_voucher(): void
    {
        config(['radius.enabled' => true]);
        PlatformSetting::set('sms.voucher_receipt', false);
        $tenant = $this->createTenant('radius-sale');
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'Synthetic RADIUS router']);
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'nas-'.$router->id,
            'radius_shared_secret' => str_repeat('a', 64),
        ])->saveOrFail();
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sale package',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'data_limit' => 'Unlimited',
        ]);
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'status' => 'pending',
            'reference' => 'RADIUS-SALE-'.Str::random(12),
            'meta' => ['package_id' => $package->id],
        ]);

        $first = app(PaymentService::class)->handleSuccessfulPackagePayment($tenant, $package, '0771234567', $transaction);
        $again = app(PaymentService::class)->handleSuccessfulPackagePayment($tenant, $package, '0771234567', $transaction);

        $this->assertSame($first->id, $again->id);
        $this->assertSame($transaction->id, (int) $first->fresh()->sale_transaction_id);
        $this->assertSame(1, Voucher::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $this->assertSame('completed', $transaction->fresh()->status);
    }

    private function createRadiusAccess(): array
    {
        $tenant = $this->createTenant('radius-'.Str::lower(Str::random(8)));
        $router = Router::create([
            'tenant_id' => $tenant->id,
            'name' => 'RADIUS Router',
        ]);
        // Synthetic legacy configuration; never bypass the disabled opt-in service.
        $router->forceFill(['access_backend' => 'radius', 'radius_nas_identifier' => 'nas-radius-'.$tenant->slug,
            'radius_shared_secret' => str_repeat('a', 64)])->save();
        $router->refresh();

        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One hour',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 2,
            'data_limit' => '5GB',
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'batch_code' => 'RADIUS-'.Str::upper(Str::random(8)),
            'quantity' => 1,
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $tenant->id,
            'voucher_batch_id' => $batch->id,
            'package_id' => $package->id,
            'code' => 'RADIUS-'.Str::upper(Str::random(8)),
            'status' => 'redeemed',
            'redeemed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $session = Session::create([
            'tenant_id' => $tenant->id,
            'voucher_id' => $voucher->id,
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'provisioning',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'pending_revocation' => false,
            'pending_device_ban' => false,
        ]);

        return [$tenant, $router, $voucher, $session, $package];
    }

    private function createVpnPeer(Router $router, string $tunnelIp): VpnPeer
    {
        return VpnPeer::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'name' => 'RADIUS test peer',
            'public_key' => base64_encode(random_bytes(32)),
            'tunnel_ip' => $tunnelIp,
            'enabled' => true,
        ]);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'kyc_status' => 'verified',
            'name' => 'RADIUS Test '.Str::title($slug),
            'slug' => $slug,
            'status' => 'active',
            'wallet_balance' => 0,
        ]);
    }

    private function signatureHeaders(string $method, string $path, string $body, string $timestamp, string $nonce): array
    {
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            hash('sha256', $body),
            $timestamp,
            $nonce,
        ]);

        return [
            'X-Radius-Service-Timestamp' => $timestamp,
            'X-Radius-Service-Nonce' => $nonce,
            'X-Radius-Service-Signature' => hash_hmac('sha256', $canonical, self::SERVICE_SECRET),
        ];
    }

    private function signedCall(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $remoteAddress = null,
        ?string $forwardedFor = null,
        ?string $nonce = null,
    ) {
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $headers = $this->signatureHeaders($method, $path, $body, $timestamp, $nonce ?? Str::random(32));
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $remoteAddress ?? '127.0.0.1',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        $server = array_merge($server, $this->transformHeadersToServerVars($headers));

        $uri = app()->environment('production') ? 'https://localhost'.$path : $path;

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function withProductionEnvironment(callable $callback): void
    {
        $app = app();
        $environment = $app->environment();
        $app->instance('env', 'production');

        try {
            $callback();
        } finally {
            $app->instance('env', $environment);
        }
    }
}
