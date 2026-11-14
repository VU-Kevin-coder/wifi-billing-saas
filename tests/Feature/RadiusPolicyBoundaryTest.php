<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\RadiusAccountingEvent;
use App\Models\RadiusAccountingSession;
use App\Models\RadiusDisconnectCommand;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\PaymentService;
use App\Services\RadiusAccountingService;
use App\Services\RadiusRouterCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

        $secret = app(RadiusRouterCredentialService::class)->optIn($router, 'nas-secret-test');
        $router->refresh();
        $stored = DB::table('routers')->where('id', $router->id)->value('radius_shared_secret');

        $this->assertSame(64, strlen($secret));
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
        $first->assertOk()->assertExactJson(['decision' => 'reject']);

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
        )->assertOk()->assertExactJson(['decision' => 'reject']);
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
            $this->assertSame(200, $response->status(), $response->getContent());
            $response->assertExactJson(['decision' => 'reject']);

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

    public function test_all_private_policy_endpoints_return_expected_runtime_responses(): void
    {
        config([
            'radius_policy.service_secret' => self::SERVICE_SECRET,
            'radius_policy.nonce_cache_store' => null,
        ]);
        [$tenant, $router, $voucher, $session] = $this->createRadiusAccess();
        $eventTime = now()->subSeconds(30)->startOfSecond();

        $this->signedCall('POST', '/internal/radius/v1/authorize', [
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'api-runtime-session',
            'voucher_code' => $voucher->code,
        ])->assertOk()->assertJsonPath('decision', 'accept');

        $this->signedCall('POST', '/internal/radius/v1/accounting-events', [
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'api-runtime-session',
            'status_type' => 'Start',
            'event_time' => $eventTime->toIso8601String(),
            'input_octets' => 1,
            'output_octets' => 2,
            'voucher_code' => $voucher->code,
        ])->assertOk()->assertJsonPath('accepted', true);

        $this->assertSame('active', $session->fresh()->status);
        $this->assertTrue(app(RadiusAccountingService::class)->requestDisconnect(
            $session,
            'revocation:'.$session->id.':disconnected',
        ));

        $commands = $this->signedCall('GET', '/internal/radius/v1/disconnect-commands')
            ->assertOk()
            ->assertJsonCount(1, 'commands')
            ->json('commands');
        $this->signedCall(
            'POST',
            '/internal/radius/v1/disconnect-commands/'.$commands[0]['command_id'].'/ack',
            ['result' => 'delivered'],
        )->assertOk()->assertJsonPath('delivery_state', 'delivery_acknowledged');

        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertSame((int) $tenant->id, (int) $router->fresh()->tenant_id);
    }

    public function test_authorization_is_router_tenant_and_entitlement_scoped(): void
    {
        [$tenant, $router, $voucher, $session, $package] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);

        $decision = $service->authorize('nas-radius-'.$tenant->slug, 'nas-auth-session', $voucher->code);

        $this->assertSame('accept', $decision['decision']);
        $this->assertSame(10, $decision['policy']['rate_limit_mbps']);
        $this->assertSame(2, $decision['policy']['device_limit']);
        $this->assertSame(5368709120, $decision['policy']['data_limit_bytes']);
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

    public function test_accounting_is_idempotent_monotonic_and_terminal_stop_closes_session(): void
    {
        [$tenant, $router, $voucher, $session] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);
        $startTime = now()->subSeconds(30)->startOfSecond();
        $start = [
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'reused-nas-session',
            'status_type' => 'Start',
            'nas_session_id' => 'reused-nas-session',
            'event_time' => $startTime->toIso8601String(),
            'input_octets' => 100,
            'output_octets' => 200,
            'voucher_code' => $voucher->code,
        ];

        $this->assertTrue($service->ingestAccountingEvent($start)['accepted']);
        $duplicate = $service->ingestAccountingEvent($start);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(1, RadiusAccountingEvent::query()->count());
        $this->assertSame('active', $session->fresh()->status);

        $interim = array_merge($start, [
            'status_type' => 'Interim-Update',
            'event_time' => $startTime->addMinute()->toIso8601String(),
            'input_octets' => 500,
            'output_octets' => 700,
        ]);
        $this->assertTrue($service->ingestAccountingEvent($interim)['accepted']);

        $stale = array_merge($interim, [
            'event_time' => $startTime->addSeconds(30)->toIso8601String(),
            'input_octets' => 300,
        ]);
        $this->assertTrue($service->ingestAccountingEvent($stale)['quarantined']);
        $this->assertSame(500, $session->fresh()->bytes_in);
        $this->assertSame(700, $session->fresh()->bytes_out);

        $stop = array_merge($interim, [
            'status_type' => 'Stop',
            'event_time' => $startTime->addMinutes(2)->toIso8601String(),
            'input_octets' => 800,
            'output_octets' => 900,
            'termination_cause' => 'User-Request',
        ]);
        $this->assertTrue($service->ingestAccountingEvent($stop)['accepted']);
        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertSame('stopped', RadiusAccountingSession::query()->sole()->state);

        $replayedStop = $service->ingestAccountingEvent($stop);
        $this->assertTrue($replayedStop['duplicate']);
        $this->assertSame(4, RadiusAccountingEvent::query()->count());

        $this->assertSame((int) $tenant->id, (int) $router->fresh()->tenant_id);
    }

    public function test_disconnect_delivery_ack_is_not_disconnect_confirmation(): void
    {
        [$tenant, $router, $voucher, $session] = $this->createRadiusAccess();
        $service = app(RadiusAccountingService::class);
        $eventTime = now()->subSeconds(30)->startOfSecond();

        $service->ingestAccountingEvent([
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'disconnect-target-session',
            'status_type' => 'Start',
            'nas_session_id' => 'disconnect-target-session',
            'event_time' => $eventTime->toIso8601String(),
            'input_octets' => 10,
            'output_octets' => 20,
            'voucher_code' => $voucher->code,
        ]);

        $this->assertTrue($service->requestDisconnect($session, 'revocation:'.$session->id.':disconnected'));
        $this->assertTrue($service->requestDisconnect($session, 'revocation:'.$session->id.':disconnected'));
        $this->assertSame(1, RadiusDisconnectCommand::query()->count());

        $commands = $service->claimDisconnectCommands();
        $this->assertCount(1, $commands);
        $this->assertSame($router->radius_nas_identifier, $commands[0]['nas_identifier']);
        $this->assertArrayNotHasKey('radius_shared_secret', $commands[0]);

        $ack = $service->acknowledgeDisconnectCommand($commands[0]['command_id'], 'delivered');
        $this->assertSame('delivery_acknowledged', $ack['state']);
        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertTrue($session->fresh()->pending_revocation);

        $this->assertCount(0, $service->claimDisconnectCommands());
        $this->travel(31)->seconds();
        $retryCommands = $service->claimDisconnectCommands();
        $this->assertCount(1, $retryCommands);
        $this->assertSame($commands[0]['command_id'], $retryCommands[0]['command_id']);
        $service->acknowledgeDisconnectCommand($retryCommands[0]['command_id'], 'delivered');

        $service->ingestAccountingEvent([
            'nas_identifier' => $router->radius_nas_identifier,
            'nas_session_id' => 'disconnect-target-session',
            'status_type' => 'Stop',
            'event_time' => $eventTime->addMinute()->toIso8601String(),
            'input_octets' => 10,
            'output_octets' => 20,
            'voucher_code' => $voucher->code,
        ]);
        $this->assertSame('disconnected', $session->fresh()->status);
        $this->assertSame((int) $tenant->id, (int) $router->fresh()->tenant_id);
    }

    public function test_verified_sale_writes_typed_voucher_link_and_replay_returns_same_voucher(): void
    {
        config(['site_agent.required' => false]);
        PlatformSetting::set('sms.voucher_receipt', false);
        $tenant = $this->createTenant('radius-sale');
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
        app(RadiusRouterCredentialService::class)->optIn($router, 'nas-radius-'.$tenant->slug);
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

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
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
