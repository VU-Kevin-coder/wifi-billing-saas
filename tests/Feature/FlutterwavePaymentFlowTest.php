<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlutterwavePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.flutterwave.com/v3';

    private const PAYMENT_STATUS_TOKEN = 'test-payment-status-token';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-key');
        PlatformSetting::setSecret('flutterwave_webhook_secret', 'test-webhook-hash');
        config(['payments.simulation_enabled' => false]);
    }

    public function test_initiation_sends_uganda_mobile_money_charge_and_keeps_internal_reference(): void
    {
        Http::fake([
            self::API_URL.'/charges?type=mobile_money_uganda' => Http::response([
                'status' => 'success',
                'data' => ['id' => 12345],
                'meta' => ['authorization' => ['redirect' => 'https://payments.flutterwave.com/confirm']],
            ]),
        ]);

        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $reference = $transaction->reference;

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction,
            null,
            'https://acme.example/portal/success'
        );

        $transaction->refresh();
        $this->assertSame('pending', $result['status']);
        $this->assertSame('https://payments.flutterwave.com/confirm', $result['authorization_url']);
        $this->assertSame($reference, $transaction->reference);
        $this->assertSame('12345', $transaction->provider_reference);

        Http::assertSent(function (ClientRequest $request) use ($reference): bool {
            return $request->url() === self::API_URL.'/charges?type=mobile_money_uganda'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-secret-key')
                && $request['phone_number'] === '256771234567'
                && $request['network'] === 'MTN'
                && $request['currency'] === 'UGX'
                && $request['email'] === 'billing@acme.test'
                && $request['tx_ref'] === $reference;
        });
    }

    public function test_uncertain_charge_response_stays_pending_for_webhook_reconciliation(): void
    {
        Http::fake([
            self::API_URL.'/charges?type=mobile_money_uganda' => Http::response([
                'status' => 'error',
                'message' => 'Temporary provider error',
            ], 503),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );

        $this->assertSame('pending', $result['status']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('unknown', $transaction->fresh()->meta['flutterwave_charge_submission']);
    }

    public function test_webhook_verifies_charge_before_fulfillment_and_is_idempotent(): void
    {
        [$tenant, , $transaction] = $this->createPendingPayment([
            'provider_reference' => '12345',
        ]);
        Http::fake([
            self::API_URL.'/transactions/12345/verify' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 12345,
                    'tx_ref' => $transaction->reference,
                    'amount' => 1000,
                    'currency' => 'UGX',
                    'status' => 'successful',
                ],
            ]),
        ]);

        $payload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 12345,
                'tx_ref' => $transaction->reference,
                'status' => 'successful',
            ],
        ];

        $this->withHeader('verif-hash', 'test-webhook-hash')
            ->postJson('/api/webhooks/flutterwave', $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $transaction->refresh();
        $tenant->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertNotEmpty($transaction->meta['voucher_id']);
        $this->assertSame('950.00', $tenant->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('sessions_log', 0);

        $this->getJson('/api/payments/'.self::PAYMENT_STATUS_TOKEN)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('voucher_code', $transaction->meta['voucher_code']);

        $this->postJson('/api/payments/'.self::PAYMENT_STATUS_TOKEN.'/activate')
            ->assertOk()
            ->assertJsonPath('status', 'activated');
        $this->postJson('/api/payments/'.self::PAYMENT_STATUS_TOKEN.'/activate')
            ->assertOk()
            ->assertJsonPath('status', 'activated');
        $this->assertDatabaseCount('sessions_log', 1);

        $this->withHeader('verif-hash', 'test-webhook-hash')
            ->postJson('/api/webhooks/flutterwave', $payload)
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_webhook_does_not_fulfill_a_charge_that_flutterwave_reports_pending(): void
    {
        [, , $transaction] = $this->createPendingPayment(['provider_reference' => '12345']);
        Http::fake([
            self::API_URL.'/transactions/12345/verify' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 12345,
                    'tx_ref' => $transaction->reference,
                    'amount' => 1000,
                    'currency' => 'UGX',
                    'status' => 'pending',
                ],
            ]),
        ]);

        $this->withHeader('verif-hash', 'test-webhook-hash')
            ->postJson('/api/webhooks/flutterwave', [
                'event' => 'charge.completed',
                'data' => ['id' => 12345, 'tx_ref' => $transaction->reference, 'status' => 'successful'],
            ])
            ->assertStatus(202)
            ->assertJson(['status' => 'pending']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_webhook_rejects_bad_signatures_and_mismatched_verified_amounts(): void
    {
        [, , $transaction] = $this->createPendingPayment(['provider_reference' => '12345']);

        $payload = [
            'event' => 'charge.completed',
            'data' => ['id' => 12345, 'tx_ref' => $transaction->reference, 'status' => 'successful'],
        ];
        $this->withHeader('verif-hash', 'wrong-hash')
            ->postJson('/api/webhooks/flutterwave', $payload)
            ->assertUnauthorized();

        Http::fake([
            self::API_URL.'/transactions/12345/verify' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 12345,
                    'tx_ref' => $transaction->reference,
                    'amount' => 10,
                    'currency' => 'UGX',
                    'status' => 'successful',
                ],
            ]),
        ]);

        $this->withHeader('verif-hash', 'test-webhook-hash')
            ->postJson('/api/webhooks/flutterwave', $payload)
            ->assertStatus(422)
            ->assertJson(['status' => 'payment_details_mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_explicit_simulation_mode_still_completes_payments_without_provider_credentials(): void
    {
        PlatformSetting::query()->where('key', 'flutterwave_secret_key')->delete();
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

    private function createPendingPayment(array $transactionOverrides = []): array
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
        $transaction = Transaction::create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'status' => 'pending',
            'reference' => 'ONL-TEST-001',
            'phone' => '0771234567',
            'description' => 'Online Purchase: One hour',
            'meta' => [
                'package_id' => $package->id,
                'phone' => '0771234567',
                'payment_token_hash' => hash('sha256', self::PAYMENT_STATUS_TOKEN),
            ],
        ], $transactionOverrides));

        return [$tenant, $package, $transaction];
    }
}
