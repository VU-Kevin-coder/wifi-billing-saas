<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payments\PaymentGatewayService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileMoneyPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.sandbox.pawapay.io';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::set('payment.active_gateway', 'pawapay');
        PlatformSetting::setSecret('payment.pawapay.api_token', 'pawapay-test-token');
        PlatformSetting::set('payment.pawapay.environment', 'sandbox');
        PlatformSetting::set('currency', 'UGX');
    }

    public function test_active_pawapay_gateway_submits_a_uganda_mobile_money_collection(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v2/predict-provider')) {
                return Http::response(['provider' => 'MTN_UGA', 'phoneNumber' => '256771234567']);
            }

            return Http::response([
                'status' => 'ACCEPTED',
                'depositId' => $request['depositId'],
            ]);
        });

        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction,
            'customer@example.test',
        );

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $result['status']);
        $this->assertSame('pawapay', $fresh->meta['payment_provider']);
        $this->assertSame('submitted', $fresh->meta['provider_submission']);
        $this->assertSame('pending', $fresh->status);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/deposits')
            && $request->hasHeader('Authorization', 'Bearer pawapay-test-token')
            && $request['amount'] === '1000'
            && $request['currency'] === 'UGX'
            && $request['payer']['accountDetails']['phoneNumber'] === '256771234567'
            && $request['clientReferenceId'] === $fresh->reference
            && $request['depositId'] === $fresh->provider_reference);
    }

    public function test_mobile_money_rejects_non_ugx_or_fractional_package_prices(): void
    {
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $package->update(['currency' => 'KES']);

        $result = app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_pawapay_status_must_match_local_reference_amount_and_currency(): void
    {
        $providerReference = (string) Str::uuid();
        [$tenant, $package, $transaction] = $this->createPendingPayment([
            'provider_reference' => $providerReference,
            'meta' => [
                'payment_provider' => 'pawapay',
                'provider_transaction_id' => $providerReference,
            ],
        ]);
        Http::fake([
            self::API_URL.'/v2/deposits/'.$providerReference => Http::response([
                'depositId' => $providerReference,
                'clientReferenceId' => $transaction->reference,
                'amount' => '999',
                'currency' => 'UGX',
                'status' => 'COMPLETED',
            ]),
        ]);

        $result = app(PaymentGatewayService::class)->verifyCollection($transaction);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_iotec_uses_oauth_and_the_selected_wallet_for_collections(): void
    {
        PlatformSetting::set('payment.active_gateway', 'iotec');
        PlatformSetting::setSecret('payment.iotec.client_id', 'iotec-client-id');
        PlatformSetting::setSecret('payment.iotec.client_secret', 'iotec-client-secret');
        PlatformSetting::set('payment.iotec.wallet_id', 'c4a76055-9a7c-4120-8d74-7557f315443d');
        PlatformSetting::setSecret('payment.iotec.webhook_secret', 'iotec-callback-secret');
        Http::preventStrayRequests();
        Http::fake([
            'https://id.iotec.io/connect/token' => Http::response([
                'access_token' => 'iotec-access-token',
                'expires_in' => 3600,
            ]),
            'https://pay.iotec.io/api/collections/collect' => Http::response([
                'id' => 'iotec-transaction-id',
                'externalId' => 'ONL-IOTEC-001',
                'status' => 'SENTTOVENDOR',
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment([
            'reference' => 'ONL-IOTEC-001',
        ]);

        $result = app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $result['status']);
        $this->assertSame('iotec', $fresh->meta['payment_provider']);
        $this->assertSame('iotec-transaction-id', $fresh->meta['provider_transaction_id']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://id.iotec.io/connect/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'iotec-client-id'
            && $request['client_secret'] === 'iotec-client-secret');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://pay.iotec.io/api/collections/collect'
            && $request->hasHeader('Authorization', 'Bearer iotec-access-token')
            && $request['walletId'] === 'c4a76055-9a7c-4120-8d74-7557f315443d'
            && $request['externalId'] === $fresh->reference
            && $request['payer'] === '256771234567'
            && $request['currency'] === 'UGX');
    }

    public function test_obpay_collection_uses_the_active_provider_and_callback_route(): void
    {
        PlatformSetting::set('payment.active_gateway', 'obpay');
        PlatformSetting::setSecret('payment.obpay.secret_key', 'obpay-test-secret');
        PlatformSetting::setSecret('payment.obpay.webhook_secret', 'obpay-webhook-secret');
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            return Http::response([
                'success' => true,
                'data' => ['reference' => $request['reference'], 'status' => 'pending'],
            ]);
        });
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('obpay', $transaction->fresh()->meta['payment_provider']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/collect')
            && $request->hasHeader('Authorization', 'Bearer obpay-test-secret')
            && $request['reference'] === $transaction->reference
            && $request['amount'] === 1000
            && $request['callback_url'] === route('api.webhooks.obpay'));
    }

    public function test_unconfigured_active_gateway_fails_closed(): void
    {
        PlatformSetting::query()->where('key', 'payment.pawapay.api_token')->delete();
        [$tenant, $package, $transaction] = $this->createPendingPayment();

        $result = app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
        Http::assertNothingSent();
    }

    public function test_iotec_webhook_rejects_an_invalid_bearer_secret(): void
    {
        PlatformSetting::setSecret('payment.iotec.webhook_secret', 'callback-secret');

        $this->postJson('/api/webhooks/iotec', [
            'id' => 'provider-transaction',
            'externalId' => 'unknown-reference',
            'status' => 'SUCCESS',
        ], ['Authorization' => 'Bearer incorrect-secret'])
            ->assertUnauthorized()
            ->assertJson(['status' => 'unauthorized']);

        Http::assertNothingSent();
    }

    private function createPendingPayment(array $overrides = []): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Wi-Fi',
            'slug' => 'test-wifi',
            'status' => 'active',
            'contact_email' => 'billing@example.test',
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
            'reference' => 'ONL-'.strtoupper(Str::random(10)),
            'phone' => '0771234567',
            'meta' => ['package_id' => $package->id, 'phone' => '0771234567'],
        ], $overrides));

        return [$tenant, $package, $transaction];
    }
}
