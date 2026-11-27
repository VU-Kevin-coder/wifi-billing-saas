<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\Subscriptions\ActiveVoucherSubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ActiveVoucherSubscriptionGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.obpay.base_url' => 'https://obpay.online/api/public/v1',
            'services.obpay.callback_url' => 'https://billing.example.test/api/webhooks/obpay',
        ]);
    }

    public function test_active_pawapay_collection_verifies_against_exact_reference_amount_and_currency(): void
    {
        PlatformSetting::set('payment.active_gateway', 'pawapay');
        PlatformSetting::setSecret('payment.pawapay.api_token', 'pawa-test-token');
        PlatformSetting::set('payment.pawapay.environment', 'sandbox');
        $reference = (string) Str::uuid();
        $mismatchedReference = false;

        Http::fake(function (Request $request) use ($reference, &$mismatchedReference) {
            if (str_ends_with($request->url(), '/v2/predict-provider')) {
                return Http::response(['provider' => 'MTN_UGA', 'phoneNumber' => '256771234567']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/deposits')) {
                return Http::response(['status' => 'ACCEPTED', 'depositId' => $request['depositId']]);
            }

            return Http::response([
                'depositId' => $reference,
                'clientReferenceId' => $mismatchedReference ? 'different-reference' : $reference,
                'amount' => '120000',
                'currency' => 'UGX',
                'status' => 'COMPLETED',
            ]);
        });

        $gateway = app(ActiveVoucherSubscriptionGateway::class);
        $context = $gateway->context();
        $this->assertNotNull($context);
        $this->assertSame('pawapay', $context->provider);
        $this->assertSame('sandbox', $context->environment);
        $gateway->start($reference, 120000, 'UGX', '+256771234567', $context);

        $this->assertSame([
            'reference' => $reference,
            'amount' => 120000,
            'currency' => 'UGX',
            'status' => 'paid',
        ], $gateway->verify($reference, $context));
        $mismatchedReference = true;
        $this->assertNull($gateway->verify($reference, $context));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/deposits')
            && $request->hasHeader('Authorization', 'Bearer pawa-test-token')
            && $request['amount'] === '120000'
            && $request['currency'] === 'UGX'
            && $request['depositId'] === $reference
            && $request['clientReferenceId'] === $reference);
    }

    public function test_active_iotec_collection_uses_the_configured_wallet_and_authenticated_lookup(): void
    {
        PlatformSetting::set('payment.active_gateway', 'iotec');
        PlatformSetting::setSecret('payment.iotec.client_id', 'client-id');
        PlatformSetting::setSecret('payment.iotec.client_secret', 'client-secret');
        PlatformSetting::setSecret('payment.iotec.webhook_secret', 'callback-secret');
        PlatformSetting::set('payment.iotec.wallet_id', 'wallet-123');
        $reference = (string) Str::uuid();
        Http::fake(function (Request $request) use ($reference) {
            if ($request->url() === 'https://id.iotec.io/connect/token') {
                return Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]);
            }
            if (str_ends_with($request->url(), '/api/collections/collect')) {
                return Http::response([
                    'id' => 'provider-collection-123',
                    'externalId' => $reference,
                    'status' => 'SENTTOVENDOR',
                ]);
            }

            return Http::response([
                'id' => 'provider-collection-123',
                'externalId' => $reference,
                'amount' => 120000,
                'currency' => 'UGX',
                'status' => 'SUCCESS',
            ]);
        });

        $gateway = app(ActiveVoucherSubscriptionGateway::class);
        $context = $gateway->context();
        $this->assertNotNull($context);
        $gateway->start($reference, 120000, 'UGX', '+256771234567', $context);
        $this->assertSame('paid', $gateway->verify($reference, $context)['status']);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/collections/collect')
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['walletId'] === 'wallet-123'
            && $request['externalId'] === $reference
            && $request['payer'] === '256771234567'
            && $request['currency'] === 'UGX');
    }

    public function test_active_obpay_collection_and_status_are_normalized_only_after_authenticated_lookup(): void
    {
        PlatformSetting::set('payment.active_gateway', 'obpay');
        PlatformSetting::setSecret('payment.obpay.secret_key', 'obpay-api-key');
        PlatformSetting::setSecret('payment.obpay.webhook_secret', 'obpay-webhook-key');
        $reference = (string) Str::uuid();
        Http::fake(function (Request $request) use ($reference) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'reference' => $reference,
                        'status' => 'pending',
                        'prompt_sent' => false,
                        'checkout_url' => 'https://checkout.obpay.online/pay/order-123',
                    ],
                ]);
            }

            return Http::response([
                'success' => true,
                'data' => [
                    'reference' => $reference,
                    'amount' => 120000,
                    'currency' => 'UGX',
                    'status' => 'SUCCESS',
                ],
            ]);
        });

        $gateway = app(ActiveVoucherSubscriptionGateway::class);
        $context = $gateway->context();
        $this->assertNotNull($context);
        $checkoutUrl = $gateway->start(
            $reference,
            120000,
            'UGX',
            '+256771234567',
            $context,
            'https://billing.example.test/operator/tenant-a/billing/invoices/1',
        );
        $this->assertSame('https://checkout.obpay.online/pay/order-123', $checkoutUrl);
        $this->assertSame('paid', $gateway->verify($reference, $context)['status']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer obpay-api-key')
            && ($request['reference'] ?? null) === $reference
            && ($request['amount'] ?? null) === 120000
            && ($request['redirect_url'] ?? null) === 'https://billing.example.test/operator/tenant-a/billing/invoices/1');
    }

    public function test_attempt_context_cannot_be_verified_after_gateway_credentials_change(): void
    {
        PlatformSetting::set('payment.active_gateway', 'pawapay');
        PlatformSetting::setSecret('payment.pawapay.api_token', 'original-token');
        $gateway = app(ActiveVoucherSubscriptionGateway::class);
        $context = $gateway->context();
        $this->assertNotNull($context);

        PlatformSetting::setSecret('payment.pawapay.api_token', 'replacement-token');
        try {
            $gateway->verify((string) Str::uuid(), $context);
            $this->fail('A different provider account must not verify an existing attempt.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The subscription payment gateway configuration changed.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
