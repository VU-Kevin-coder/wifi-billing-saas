<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PesapalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $platformAdmin;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'status' => 'active',
            'commission_rate' => 10,
            'wallet_balance' => 0,
        ]);

        $this->platformAdmin = User::factory()->create(['tenant_id' => null]);
        $this->platformAdmin->assignRole('platform_admin');

        $this->package = Package::create([
            'tenant_id' => $this->tenant->id,
            'name' => '1 Hour Pass',
            'price' => 2000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'hours',
            'speed_limit_mbps' => 10,
            'device_limit' => 1,
        ]);

        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    public function test_platform_admin_can_configure_pesapal_and_it_is_audited(): void
    {
        $this->actingAs($this->platformAdmin)
            ->put(route('platform.configuration.pesapal.update'), [
                'pesapal_consumer_key' => 'test-consumer-key-xyz',
                'pesapal_consumer_secret' => 'super-secret-secret-123',
                'pesapal_environment' => 'sandbox',
                'pesapal_ipn_id' => 'ipn-guid-456',
            ])
            ->assertRedirect(route('platform.configuration.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('test-consumer-key-xyz', PlatformSetting::get('pesapal_consumer_key'));
        $this->assertSame('super-secret-secret-123', PlatformSetting::getSecret('pesapal_consumer_secret'));
        $this->assertSame('sandbox', PlatformSetting::get('pesapal_environment'));
        $this->assertSame('ipn-guid-456', PlatformSetting::get('pesapal_ipn_id'));
        $this->assertSame('Configured (Untested)', PlatformSetting::get('pesapal_status'));

        $log = AuditLog::where('action', 'platform.config.updated')->where('meta->section', 'pesapal')->firstOrFail();
        $this->assertSame(['pesapal_consumer_key', 'pesapal_consumer_secret', 'pesapal_ipn_id'], $log->meta['credentials_changed']);
        $this->assertStringNotContainsString('super-secret-secret-123', json_encode($log->meta));
    }

    public function test_purchase_redirects_to_pesapal_hosted_checkout_when_configured(): void
    {
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');
        PlatformSetting::set('pesapal_environment', 'sandbox');

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'mock-token-abc',
                'status' => '200',
            ]),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'trk-12345-67890',
                'merchant_reference' => 'ONL-TEST-REF',
                'redirect_url' => 'https://cybqa.pesapal.com/pesapalv3/checkout?orderTrackingId=trk-12345-67890',
                'status' => '200',
            ]),
        ]);

        $response = $this->post(route('portal.direct.purchase', ['tenant' => $this->tenant->slug]), [
            'package_id' => $this->package->id,
            'phone' => '0771234567',
            'email' => 'buyer@example.test',
            '_idempotency_key' => $this->idempotencyKey(),
        ]);

        $response->assertRedirect('https://cybqa.pesapal.com/pesapalv3/checkout?orderTrackingId=trk-12345-67890');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Transactions/SubmitOrderRequest')
            && $request['billing_address']['email_address'] === 'buyer@example.test');

        $transaction = Transaction::withoutTenancy()->where('tenant_id', $this->tenant->id)->latest()->firstOrFail();
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('trk-12345-67890', $transaction->provider_reference);
        $this->assertSame('pesapal', $transaction->meta['payment_provider']);
    }

    public function test_pesapal_order_uses_the_package_currency(): void
    {
        $this->package->update(['currency' => 'KES']);
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response(['token' => 'mock-token']),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'trk-kes-123',
                'redirect_url' => 'https://cybqa.pesapal.com/checkout',
            ]),
        ]);

        $this->post(route('portal.direct.purchase', ['tenant' => $this->tenant->slug]), [
            'package_id' => $this->package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect('https://cybqa.pesapal.com/checkout');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Transactions/SubmitOrderRequest')
            && $request['currency'] === 'KES');
    }

    public function test_pesapal_uses_configured_sender_address_when_customer_email_is_blank(): void
    {
        config(['mail.from.address' => 'billing@example.test']);
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response(['token' => 'mock-token']),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'trk-email-fallback',
                'redirect_url' => 'https://cybqa.pesapal.com/checkout',
            ]),
        ]);

        $this->post(route('portal.direct.purchase', ['tenant' => $this->tenant->slug]), [
            'package_id' => $this->package->id,
            'phone' => '0771234567',
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertRedirect('https://cybqa.pesapal.com/checkout');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/Transactions/SubmitOrderRequest')
            && $request['billing_address']['email_address'] === 'billing@example.test');
    }

    public function test_pesapal_callback_fulfills_payment_on_completed_status(): void
    {
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');

        $transaction = Transaction::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 2000,
            'status' => 'pending',
            'reference' => 'ONL-CALLBACK-1',
            'provider_reference' => 'trk-callback-1',
            'phone' => '0771234567',
            'meta' => [
                'package_id' => $this->package->id,
                'phone' => '0771234567',
            ],
        ]);

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'mock-token-abc',
            ]),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus?orderTrackingId=trk-callback-1' => Http::response([
                'payment_status_description' => 'COMPLETED',
                'amount' => 2000,
                'currency' => 'UGX',
                'merchant_reference' => 'ONL-CALLBACK-1',
            ]),
        ]);

        $response = $this->get(route('portal.direct.pesapal.callback', [
            'tenant' => $this->tenant->slug,
            'OrderTrackingId' => 'trk-callback-1',
            'OrderMerchantReference' => 'ONL-CALLBACK-1',
        ]));

        $response->assertRedirect(route('portal.direct.success', ['tenant' => $this->tenant->slug]));
        $this->assertTrue(session()->has('voucher_code'));

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertEquals(1800, (float) $this->tenant->fresh()->wallet_balance); // 2000 - 10% fee
    }

    public function test_pesapal_ipn_webhook_fulfills_pending_transaction(): void
    {
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');

        $transaction = Transaction::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 2000,
            'status' => 'pending',
            'reference' => 'ONL-IPN-1',
            'provider_reference' => 'trk-ipn-1',
            'phone' => '0771234567',
            'meta' => [
                'package_id' => $this->package->id,
                'phone' => '0771234567',
            ],
        ]);

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'mock-token-abc',
            ]),
            'https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus?orderTrackingId=trk-ipn-1' => Http::response([
                'payment_status_description' => 'COMPLETED',
                'amount' => 2000,
                'currency' => 'UGX',
                'merchant_reference' => 'ONL-IPN-1',
            ]),
        ]);

        $response = $this->postJson(route('api.webhooks.pesapal'), [
            'OrderTrackingId' => 'trk-ipn-1',
            'OrderMerchantReference' => 'ONL-IPN-1',
            'OrderNotificationType' => 'IPNCHANGE',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 200,
                'orderTrackingId' => 'trk-ipn-1',
            ]);

        $this->postJson(route('api.webhooks.pesapal'), [
            'OrderTrackingId' => 'trk-ipn-1',
            'OrderMerchantReference' => 'ONL-IPN-1',
            'OrderNotificationType' => 'IPNCHANGE',
        ])->assertOk();

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertSame(1800.0, (float) $this->tenant->fresh()->wallet_balance);
    }

    #[DataProvider('invalidCompletedOrderResponsesForCallback')]
    public function test_pesapal_callback_does_not_fulfill_an_unbound_or_unverified_order(
        ?string $requestReference,
        array $providerDetails,
        int $transactionAmount,
        bool $splitOrder
    ): void {
        $transaction = $this->createPendingPesapalTransaction(
            'ONL-PESAPAL-REGRESSION-1',
            'trk-pesapal-regression-1',
            $transactionAmount
        );
        $transactions = [$transaction];

        if ($splitOrder) {
            $transactions[] = $this->createPendingPesapalTransaction(
                'ONL-PESAPAL-REGRESSION-OTHER',
                'trk-pesapal-regression-other'
            );
        }

        $this->fakePesapalCompletedStatus($providerDetails, 'trk-pesapal-regression-1');

        $response = $this->get(route('portal.direct.pesapal.callback', [
            'tenant' => $this->tenant->slug,
            'OrderTrackingId' => 'trk-pesapal-regression-1',
            'OrderMerchantReference' => $requestReference,
        ]));

        $response->assertRedirect()
            ->assertSessionHasErrors('error');
        $this->assertPesapalOrdersRemainPending($transactions);
    }

    #[DataProvider('invalidCompletedOrderResponses')]
    public function test_pesapal_ipn_does_not_fulfill_an_unbound_or_unverified_order(
        ?string $requestReference,
        array $providerDetails,
        int $transactionAmount,
        bool $splitOrder,
        int $expectedStatus
    ): void {
        $transaction = $this->createPendingPesapalTransaction(
            'ONL-PESAPAL-REGRESSION-1',
            'trk-pesapal-regression-1',
            $transactionAmount
        );
        $transactions = [$transaction];

        if ($splitOrder) {
            $transactions[] = $this->createPendingPesapalTransaction(
                'ONL-PESAPAL-REGRESSION-OTHER',
                'trk-pesapal-regression-other'
            );
        }

        $this->fakePesapalCompletedStatus($providerDetails, 'trk-pesapal-regression-1');

        $response = $this->postJson(route('api.webhooks.pesapal'), [
            'OrderTrackingId' => 'trk-pesapal-regression-1',
            'OrderMerchantReference' => $requestReference,
            'OrderNotificationType' => 'IPNCHANGE',
        ]);

        $response->assertStatus($expectedStatus);
        if ($expectedStatus === 404) {
            $response->assertJson(['status' => 'transaction_not_found']);
        } else {
            $response->assertJson([
                'orderNotificationType' => 'IPNCHANGE',
                'orderTrackingId' => 'trk-pesapal-regression-1',
                'orderMerchantReference' => $requestReference,
                'status' => 200,
            ]);
        }

        $this->assertPesapalOrdersRemainPending($transactions);
    }

    public static function invalidCompletedOrderResponses(): array
    {
        return [
            'tracking id and callback merchant reference belong to different orders' => [
                'ONL-PESAPAL-REGRESSION-OTHER',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 2000,
                    'currency' => 'UGX',
                ],
                2000,
                true,
                404,
            ],
            'callback merchant reference is missing' => [
                null,
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 2000,
                    'currency' => 'UGX',
                ],
                2000,
                false,
                404,
            ],
            'provider merchant reference is missing' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'amount' => 2000,
                    'currency' => 'UGX',
                ],
                2000,
                false,
                200,
            ],
            'provider merchant reference does not match local order' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-OTHER',
                    'amount' => 2000,
                    'currency' => 'UGX',
                ],
                2000,
                false,
                200,
            ],
            'provider amount is missing' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'currency' => 'UGX',
                ],
                2000,
                false,
                200,
            ],
            'provider amount does not match local transaction' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 1999,
                    'currency' => 'UGX',
                ],
                2000,
                false,
                200,
            ],
            'provider currency is missing' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 2000,
                ],
                2000,
                false,
                200,
            ],
            'provider currency does not match package' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 2000,
                    'currency' => 'KES',
                ],
                2000,
                false,
                200,
            ],
            'local transaction amount does not match package price' => [
                'ONL-PESAPAL-REGRESSION-1',
                [
                    'payment_status_description' => 'COMPLETED',
                    'merchant_reference' => 'ONL-PESAPAL-REGRESSION-1',
                    'amount' => 2500,
                    'currency' => 'UGX',
                ],
                2500,
                false,
                200,
            ],
        ];
    }

    public static function invalidCompletedOrderResponsesForCallback(): array
    {
        return array_map(
            static fn (array $case): array => array_slice($case, 0, 4),
            self::invalidCompletedOrderResponses()
        );
    }

    private function createPendingPesapalTransaction(
        string $reference,
        string $trackingId,
        int $amount = 2000
    ): Transaction {
        return Transaction::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => $amount,
            'status' => 'pending',
            'reference' => $reference,
            'provider_reference' => $trackingId,
            'phone' => '0771234567',
            'meta' => [
                'package_id' => $this->package->id,
                'phone' => '0771234567',
            ],
        ]);
    }

    private function fakePesapalCompletedStatus(array $providerDetails, string $trackingId): void
    {
        PlatformSetting::set('pesapal_consumer_key', 'test-key');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'test-secret');

        Http::fake([
            'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken' => Http::response([
                'token' => 'mock-token-abc',
            ]),
            "https://cybqa.pesapal.com/pesapalv3/api/Transactions/GetTransactionStatus?orderTrackingId={$trackingId}" => Http::response(
                $providerDetails
            ),
        ]);
    }

    /**
     * @param  list<Transaction>  $transactions
     */
    private function assertPesapalOrdersRemainPending(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            $this->assertSame('pending', $transaction->fresh()->status);
        }

        $this->assertDatabaseCount('vouchers', 0);
        $this->assertSame(0.0, (float) $this->tenant->fresh()->wallet_balance);
    }
}
