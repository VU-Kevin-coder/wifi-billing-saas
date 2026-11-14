<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\AirtelMoneyService;
use App\Services\MtnMomoService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileMoneyPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const MTN_URL = 'https://sandbox.momodeveloper.mtn.com';

    private const AIRTEL_URL = 'https://openapiuat.airtel.africa';

    private const PAYMENT_STATUS_TOKEN = 'test-payment-status-token';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::setSecret('mtn_subscription_key', 'mtn-subscription-test');
        PlatformSetting::setSecret('mtn_api_user', 'mtn-api-user-test');
        PlatformSetting::setSecret('mtn_api_key', 'mtn-api-key-test');
        PlatformSetting::setSecret('airtel_client_id', 'airtel-client-test');
        PlatformSetting::setSecret('airtel_client_secret', 'airtel-secret-test');
        config(['payments.simulation_enabled' => false]);
    }

    public function test_mtn_collection_uses_the_configured_credentials_and_uganda_number(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );

        $transaction->refresh();
        $this->assertSame('pending', $result['status']);
        $this->assertSame('MTN', $transaction->meta['payment_provider']);
        $this->assertSame('submitted', $transaction->meta['provider_submission']);
        $this->assertNotEmpty($transaction->provider_reference);

        Http::assertSent(function (ClientRequest $request) use ($transaction): bool {
            return $request->url() === self::MTN_URL.'/collection/v1_0/requesttopay'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer mtn-access-token')
                && $request->hasHeader('Ocp-Apim-Subscription-Key', 'mtn-subscription-test')
                && $request->hasHeader('X-Reference-Id', $transaction->provider_reference)
                && $request['payer']['partyId'] === '256771234567'
                && $request['externalId'] === $transaction->reference
                && $request['currency'] === 'EUR';
        });
    }

    public function test_mobile_money_rejects_non_ugx_packages_instead_of_charging_the_wrong_currency(): void
    {
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $package->update(['currency' => 'KES']);

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction,
        );

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('supports UGX packages only', $result['error']);
        $this->assertSame('failed', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_mobile_money_purchase_redirects_to_pending_and_polling_reconciles_payment(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/collection/token/')) {
                return Http::response(['access_token' => 'mtn-access-token']);
            }

            if (str_ends_with($request->url(), '/collection/v1_0/requesttopay')) {
                return Http::response([], 202);
            }

            $reference = rawurldecode(basename($request->url()));
            $transaction = Transaction::withoutTenancy()
                ->where('provider_reference', $reference)
                ->firstOrFail();

            return Http::response([
                'amount' => '1000',
                'currency' => 'EUR',
                'externalId' => $transaction->reference,
                'status' => 'SUCCESSFUL',
            ]);
        });
        [$tenant, $package, $unusedTransaction] = $this->createPendingPayment();
        $unusedTransaction->delete();

        $response = $this->post('/portal/'.$tenant->slug.'/purchase', [
            'package_id' => $package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ]);

        $transaction = Transaction::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_reference')
            ->firstOrFail();
        $pendingUrl = route('portal.direct.pending', [
            'tenant' => $tenant->slug,
            'transaction' => $transaction->id,
        ]);
        $response->assertRedirect($pendingUrl);

        $this->get($pendingUrl)
            ->assertOk()
            ->assertSee('Waiting for payment')
            ->assertSee($transaction->reference);

        $checkUrl = route('portal.direct.check', [
            'tenant' => $tenant->slug,
            'transaction' => $transaction->id,
        ]);
        $successUrl = route('portal.direct.success', ['tenant' => $tenant->slug]);
        $this->getJson($checkUrl)
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'redirect_url' => $successUrl,
            ]);

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseHas('vouchers', [
            'tenant_id' => $tenant->id,
            'status' => 'redeemed',
            'redeemed_by' => '0771234567',
        ]);
        $this->assertDatabaseCount('sessions_log', 1);
        $this->get($successUrl)->assertOk()->assertSee('Waiting for payment');
        $this->get($successUrl)
            ->assertOk()
            ->assertSee('id="countdown"', false)
            ->assertSee('seconds = 3', false);

        $voucherCode = $transaction->fresh()->meta['voucher_code'];
        $this->get($successUrl.'?voucher='.urlencode($voucherCode))
            ->assertRedirect($successUrl);
        $this->get($successUrl.'?voucher=NOT-A-PAID-VOUCHER')
            ->assertRedirect($successUrl);
    }

    public function test_successful_payment_fulfillment_is_idempotent_and_starts_the_session_immediately(): void
    {
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $paymentService = app(PaymentService::class);

        $voucher = $paymentService->handleSuccessfulPackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );
        $sameVoucher = $paymentService->handleSuccessfulPackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction->fresh()
        );

        $this->assertTrue($sameVoucher->is($voucher));
        $this->assertSame('redeemed', $voucher->fresh()->status);
        $this->assertNotNull($voucher->fresh()->redeemed_at);
        $this->assertDatabaseCount('sessions_log', 1);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
    }

    public function test_local_momo_simulator_redirects_to_success_and_can_be_replayed_without_duplicate_fulfillment(): void
    {
        config(['payments.simulation_enabled' => true]);
        [$tenant, , $transaction] = $this->createPendingPayment();
        $successUrl = route('portal.direct.success', ['tenant' => $tenant->slug]);

        $this->get('/simulate-momo-success/'.$transaction->reference)
            ->assertRedirect($successUrl);

        $completed = $transaction->fresh();
        $voucherCode = $completed->meta['voucher_code'];
        $this->get($successUrl)
            ->assertOk()
            ->assertSee($voucherCode)
            ->assertSee('seconds = 3', false);

        $this->get('/simulate-momo-success/'.$transaction->reference)
            ->assertRedirect($successUrl);

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('sessions_log', 1);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
    }

    public function test_momo_simulator_is_not_available_outside_local_or_test_environments(): void
    {
        [, , $transaction] = $this->createPendingPayment();
        config(['app.env' => 'production']);

        try {
            $this->get('/simulate-momo-success/'.$transaction->reference)
                ->assertNotFound();
        } finally {
            config(['app.env' => 'testing']);
        }

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_pending_page_is_restricted_to_the_payment_session_and_tenant(): void
    {
        [$tenant, , $transaction] = $this->createPendingPayment();
        $pendingUrl = route('portal.direct.pending', [
            'tenant' => $tenant->slug,
            'transaction' => $transaction->id,
        ]);

        $this->get($pendingUrl)->assertNotFound();

        $this->withSession(['payment_token' => self::PAYMENT_STATUS_TOKEN])
            ->get(route('portal.direct.pending', [
                'tenant' => 'another-tenant',
                'transaction' => $transaction->id,
            ]))
            ->assertNotFound();
    }

    public function test_tenant_subdomain_purchase_redirects_to_its_pending_page(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
        ]);
        [$tenant, $package, $unusedTransaction] = $this->createPendingPayment();
        $unusedTransaction->delete();

        $this->post('http://'.$tenant->slug.'.localhost/portal/purchase', [
            'package_id' => $package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect(
            route('portal.pending', [
                'tenant' => $tenant->slug,
                'transaction' => Transaction::withoutTenancy()
                    ->where('tenant_id', $tenant->id)
                    ->whereNotNull('provider_reference')
                    ->value('id'),
            ])
        );

        $transaction = Transaction::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_reference')
            ->firstOrFail();
        $this->get(route('portal.pending', [
            'tenant' => $tenant->slug,
            'transaction' => $transaction->id,
        ]))->assertOk()->assertSee($transaction->reference);
    }

    public function test_mtn_webhook_only_fulfills_after_provider_status_verification(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
            self::MTN_URL.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1000',
                'currency' => 'EUR',
                'externalId' => 'ONL-TEST-001',
                'status' => 'SUCCESSFUL',
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $transaction->refresh();

        $this->postJson('/api/webhooks/mtn', ['referenceId' => $transaction->provider_reference])
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        Http::assertSent(function (ClientRequest $request) use ($transaction): bool {
            return $request->method() === 'GET'
                && str_ends_with($request->url(), '/collection/v1_0/requesttopay/'.$transaction->provider_reference)
                && $request->hasHeader('Authorization', 'Bearer mtn-access-token');
        });

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);

        $this->postJson('/api/webhooks/mtn', ['referenceId' => $transaction->provider_reference])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_mtn_webhook_status_is_not_trusted_without_a_successful_provider_check(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
            self::MTN_URL.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1000',
                'currency' => 'EUR',
                'externalId' => 'ONL-TEST-001',
                'status' => 'PENDING',
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $transaction->refresh();

        $this->postJson('/api/webhooks/mtn', [
            'referenceId' => $transaction->provider_reference,
            'status' => 'SUCCESSFUL',
        ])->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_pending_portal_payment_is_deduplicated_and_retry_is_allowed_after_failure(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
        ]);
        [$tenant, $package, $unusedTransaction] = $this->createPendingPayment();
        $unusedTransaction->delete();

        $this->post("/portal/{$tenant->slug}/purchase", [
            'package_id' => $package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect();

        $this->post("/portal/{$tenant->slug}/purchase", [
            'package_id' => $package->id,
            'phone' => '256771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertSessionHasErrors('phone');

        $this->assertSame(1, Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $pending = Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('pending', $pending->status);

        $pending->update(['status' => 'failed']);

        $this->post("/portal/{$tenant->slug}/purchase", [
            'package_id' => $package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect();

        $this->assertSame(2, Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->count());
    }

    public function test_mtn_collection_with_a_mismatched_verified_amount_is_not_fulfilled(): void
    {
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
            self::MTN_URL.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '10',
                'currency' => 'EUR',
                'externalId' => 'ONL-TEST-001',
                'status' => 'SUCCESSFUL',
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $transaction->refresh();

        $this->postJson('/api/webhooks/mtn', ['referenceId' => $transaction->provider_reference])
            ->assertStatus(422)
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_airtel_collection_and_status_verification_fulfill_once(): void
    {
        Http::fake([
            self::AIRTEL_URL.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            self::AIRTEL_URL.'/merchant/v1/payments/' => Http::response([
                'status' => ['success' => true],
                'data' => ['transaction' => ['id' => 'airtel-payment-id', 'status' => 'TIP']],
            ]),
            self::AIRTEL_URL.'/standard/v1/payments/airtel-payment-id' => Http::response([
                'data' => [
                    'transaction' => [
                        'id' => 'airtel-payment-id',
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0701234567',
            $transaction
        );

        $this->assertSame('pending', $result['status']);
        $this->assertSame('airtel-payment-id', $transaction->fresh()->provider_reference);
        $this->assertSame('AIRTEL', $transaction->fresh()->meta['payment_provider'] ?? null);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === self::AIRTEL_URL.'/merchant/v1/payments/'
                && $request['subscriber']['msisdn'] === '256701234567'
                && $request['transaction']['currency'] === 'UGX'
                && $request->hasHeader('X-Country', 'UG');
        });

        $statusResponse = $this->getJson('/api/payments/status', ['X-Payment-Token' => self::PAYMENT_STATUS_TOKEN]);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/standard/v1/payments/'));
        $statusResponse->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);

        $this->postJson('/api/webhooks/airtel', ['transaction' => ['id' => 'airtel-payment-id']])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_airtel_success_status_without_a_transaction_id_is_not_fulfilled(): void
    {
        Http::fake([
            self::AIRTEL_URL.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            self::AIRTEL_URL.'/standard/v1/payments/airtel-payment-id' => Http::response([
                'data' => [
                    'transaction' => [
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);
        [$tenant, , $transaction] = $this->createPendingPayment();
        $transaction->update([
            'provider_reference' => 'airtel-payment-id',
            'meta' => array_merge($transaction->meta ?? [], ['payment_provider' => 'AIRTEL']),
        ]);

        $this->postJson('/api/webhooks/airtel', ['transaction' => ['id' => 'airtel-payment-id']])
            ->assertStatus(422)
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_airtel_success_status_accepts_matching_amount_and_currency_when_echoed(): void
    {
        Http::fake([
            self::AIRTEL_URL.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            self::AIRTEL_URL.'/standard/v1/payments/airtel-payment-id' => Http::response([
                'data' => [
                    'transaction' => [
                        'id' => 'airtel-payment-id',
                        'amount' => '1000',
                        'currency' => 'UGX',
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);
        [$tenant, , $transaction] = $this->createPendingPayment();
        $transaction->update([
            'provider_reference' => 'airtel-payment-id',
            'meta' => array_merge($transaction->meta ?? [], ['payment_provider' => 'AIRTEL']),
        ]);

        $this->postJson('/api/webhooks/airtel', ['transaction' => ['id' => 'airtel-payment-id']])
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);
    }

    #[DataProvider('airtelStatusMismatches')]
    public function test_airtel_success_status_with_a_mismatching_provider_detail_is_not_fulfilled(
        array $providerDetails
    ): void {
        Http::fake([
            self::AIRTEL_URL.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            self::AIRTEL_URL.'/standard/v1/payments/airtel-payment-id' => Http::response([
                'data' => [
                    'transaction' => array_merge([
                        'id' => 'airtel-payment-id',
                        'status' => 'TS',
                    ], $providerDetails),
                ],
            ]),
        ]);
        [$tenant, , $transaction] = $this->createPendingPayment();
        $transaction->update([
            'provider_reference' => 'airtel-payment-id',
            'meta' => array_merge($transaction->meta ?? [], ['payment_provider' => 'AIRTEL']),
        ]);

        $this->postJson('/api/webhooks/airtel', ['transaction' => ['id' => 'airtel-payment-id']])
            ->assertStatus(422)
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public static function airtelStatusMismatches(): array
    {
        return [
            'provider transaction ID differs from the queried payment' => [['id' => 'another-payment-id']],
            'provider amount differs from the local purchase' => [['amount' => '999']],
            'provider amount is not numeric' => [['amount' => 'one thousand']],
            'provider currency differs from UGX' => [['currency' => 'KES']],
        ];
    }

    public function test_airtel_success_status_is_rejected_if_local_package_price_changed(): void
    {
        Http::fake([
            self::AIRTEL_URL.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            self::AIRTEL_URL.'/standard/v1/payments/airtel-payment-id' => Http::response([
                'data' => [
                    'transaction' => [
                        'id' => 'airtel-payment-id',
                        'status' => 'TS',
                    ],
                ],
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $transaction->update([
            'provider_reference' => 'airtel-payment-id',
            'meta' => array_merge($transaction->meta ?? [], ['payment_provider' => 'AIRTEL']),
        ]);
        $package->update(['price' => 1200]);

        $this->postJson('/api/webhooks/airtel', ['transaction' => ['id' => 'airtel-payment-id']])
            ->assertStatus(422)
            ->assertJson(['status' => 'mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_unconfigured_provider_fails_closed_instead_of_issuing_a_voucher(): void
    {
        PlatformSetting::query()->whereIn('key', [
            'mtn_subscription_key',
            'mtn_api_user',
            'mtn_api_key',
        ])->delete();
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_mobile_money_api_urls_can_be_overridden_in_configuration(): void
    {
        config([
            'services.mtn_momo.sandbox_url' => 'https://mtn-api.test/',
            'services.airtel_money.sandbox_url' => 'https://airtel-api.test/',
        ]);
        Http::fake([
            'https://mtn-api.test/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            'https://mtn-api.test/collection/v1_0/requesttopay' => Http::response([], 202),
            'https://airtel-api.test/auth/oauth2/token' => Http::response(['access_token' => 'airtel-access-token']),
            'https://airtel-api.test/merchant/v1/payments/' => Http::response([
                'data' => ['transaction' => ['id' => 'airtel-payment-id']],
            ]),
        ]);
        [, $package, $transaction] = $this->createPendingPayment();

        app(MtnMomoService::class)->initiate($transaction, $package, '256771234567', 'mtn-reference');
        app(AirtelMoneyService::class)->initiate('256701234567', 1000, 'airtel-reference');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://mtn-api.test/collection/v1_0/requesttopay');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://airtel-api.test/merchant/v1/payments/');
    }

    public function test_explicit_simulation_mode_is_the_only_path_that_issues_without_provider_credentials(): void
    {
        PlatformSetting::query()->whereIn('key', [
            'mtn_subscription_key',
            'mtn_api_user',
            'mtn_api_key',
        ])->delete();
        config(['payments.simulation_enabled' => true]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );

        $this->assertSame('simulated_success', $result['status']);
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 1);
    }

    private function createPendingPayment(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Wi-Fi',
            'slug' => 'test-wifi',
            'status' => 'active',
            'contact_email' => 'billing@acme.test',
            'wallet_balance' => 0,
            'commission_rate' => 5,
        ]);
        $package = Package::create([
            'tenant_id' => $tenant->id,
            'name' => 'One hour',
            'price' => 1000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
            'is_published' => true,
        ]);
        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'status' => 'pending',
            'reference' => 'ONL-TEST-001',
            'phone' => '0771234567',
            'payment_token_hash' => hash('sha256', self::PAYMENT_STATUS_TOKEN),
            'description' => 'Online Purchase: One hour',
            'meta' => [
                'package_id' => $package->id,
                'phone' => '0771234567',
            ],
        ]);

        return [$tenant, $package, $transaction];
    }
}
