<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentGatewayCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://obpay.online/api/public/v1';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::setSecret('payment.obpay.secret_key', 'obpay-test-secret');
        PlatformSetting::setSecret('payment.obpay.webhook_secret', 'obpay-test-webhook');
        PlatformSetting::set('payment.active_gateway', 'obpay');
        PlatformSetting::set('currency', 'UGX');
    }

    public function test_obpay_collection_is_fulfilled_once_only_after_provider_status_verification(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === self::API_URL.'/collect') {
                return Http::response([
                    'success' => true,
                    'data' => ['reference' => $request['reference'], 'status' => 'pending'],
                ]);
            }

            if (str_starts_with($request->url(), self::API_URL.'/transactions/')) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'reference' => rawurldecode(basename($request->url())),
                        'amount' => 1000,
                        'currency' => 'UGX',
                        'status' => 'completed',
                    ],
                ]);
            }

            return Http::response([], 404);
        });
        [$tenant, $package] = $this->createPurchase();

        $this->post('/portal/'.$tenant->slug.'/purchase', [
            'package_id' => $package->id,
            'phone' => '0771234567',
            'customer_name' => 'Wi-Fi Customer',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect();

        $transaction = Transaction::withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'voucher_sale')
            ->firstOrFail();
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('obpay', $transaction->meta['payment_provider']);

        $payload = [
            'event' => 'collection.completed',
            'data' => ['reference' => $transaction->reference],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, 'obpay-test-webhook');

        $this->call('POST', '/api/webhooks/obpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OBPAY_SIGNATURE' => $signature,
        ], $body)->assertOk()->assertJson(['status' => 'completed']);

        $this->call('POST', '/api/webhooks/obpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OBPAY_SIGNATURE' => $signature,
        ], $body)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('sessions_log', 1);
    }

    public function test_collection_with_a_mismatching_provider_amount_is_not_fulfilled(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === self::API_URL.'/collect') {
                return Http::response([
                    'success' => true,
                    'data' => ['reference' => $request['reference'], 'status' => 'pending'],
                ]);
            }

            return Http::response([
                'success' => true,
                'data' => [
                    'reference' => rawurldecode(basename($request->url())),
                    'amount' => 900,
                    'currency' => 'UGX',
                    'status' => 'completed',
                ],
            ]);
        });
        [$tenant, $package] = $this->createPurchase();
        $this->post('/portal/'.$tenant->slug.'/purchase', [
            'package_id' => $package->id,
            'phone' => '0771234567',
            'customer_name' => 'Wi-Fi Customer',
            '_idempotency_key' => $this->idempotencyKey(),
        ]);
        $transaction = Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->firstOrFail();
        $payload = ['event' => 'collection.completed', 'data' => ['reference' => $transaction->reference]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/webhooks/obpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OBPAY_SIGNATURE' => hash_hmac('sha256', $body, 'obpay-test-webhook'),
        ], $body)->assertStatus(422)->assertJson(['status' => 'mismatch']);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_checkout_fails_closed_and_releases_the_purchase_intent_when_no_gateway_is_active(): void
    {
        PlatformSetting::set('payment.active_gateway', '');
        [$tenant, $package] = $this->createPurchase();
        $this->post('/portal/'.$tenant->slug.'/purchase', [
            'package_id' => $package->id,
            'phone' => '0771234567',
            'customer_name' => 'Wi-Fi Customer',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertSessionHasErrors('phone');

        $this->assertSame(
            'failed',
            Transaction::withoutTenancy()->where('tenant_id', $tenant->id)->firstOrFail()->status,
        );
        Http::assertNothingSent();
    }

    private function createPurchase(): array
    {
        $tenant = Tenant::create([
            'name' => 'Collection operator',
            'slug' => 'collection-operator',
            'status' => 'active',
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

        return [$tenant, $package];
    }
}
