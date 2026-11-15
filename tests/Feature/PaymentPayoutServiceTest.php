<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payments\PaymentPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentPayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_obpay_payout_is_only_completed_after_matching_provider_status(): void
    {
        PlatformSetting::setSecret('payment.obpay.secret_key', 'test-obpay-key');
        PlatformSetting::setSecret('payment.obpay.webhook_secret', 'test-obpay-webhook');
        PlatformSetting::set('currency', 'UGX');
        $transaction = $this->createWithdrawal();

        Http::fake([
            'obpay.online/api/public/v1/payouts' => Http::response([
                'success' => true,
                'data' => ['reference' => $transaction->reference, 'status' => 'pending'],
            ]),
            'obpay.online/api/public/v1/transactions/'.$transaction->reference => Http::response([
                'success' => true,
                'data' => [
                    'reference' => $transaction->reference,
                    'amount' => 10000,
                    'currency' => 'UGX',
                    'status' => 'completed',
                ],
            ]),
        ]);

        $result = app(PaymentPayoutService::class)->approve($transaction, 'obpay');

        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('obpay', $transaction->fresh()->meta['payout_gateway']);
        Http::assertSentCount(2);
    }

    public function test_mismatching_provider_payout_is_not_completed_or_refunded(): void
    {
        PlatformSetting::setSecret('payment.pawapay.api_token', 'test-pawapay-token');
        PlatformSetting::set('payment.pawapay.environment', 'sandbox');
        PlatformSetting::set('currency', 'UGX');
        $transaction = $this->createWithdrawal();

        Http::fake([
            'api.sandbox.pawapay.io/v2/predict-provider' => Http::response([
                'provider' => 'MTN',
                'phoneNumber' => '256771234567',
            ]),
            'api.sandbox.pawapay.io/v2/payouts' => function ($request) {
                return Http::response([
                    'status' => 'ACCEPTED',
                    'payoutId' => $request['payoutId'],
                ]);
            },
            'api.sandbox.pawapay.io/v2/payouts/*' => Http::response([
                'status' => 'COMPLETED',
                'payoutId' => 'unexpected-id',
                'clientReferenceId' => 'WTH-PAYOUT-001',
                'amount' => '9000',
                'currency' => 'UGX',
            ]),
        ]);

        $result = app(PaymentPayoutService::class)->approve($transaction, 'pawapay');
        $fresh = $transaction->fresh();

        $this->assertFalse($result['ok']);
        $this->assertSame('pending', $fresh->status);
        $this->assertTrue($fresh->meta['needs_manual_review']);
        $this->assertSame('40000.00', $fresh->tenant->fresh()->wallet_balance);
    }

    public function test_verified_provider_failure_refunds_the_wallet_only_once(): void
    {
        PlatformSetting::setSecret('payment.pawapay.api_token', 'test-pawapay-token');
        PlatformSetting::set('payment.pawapay.environment', 'sandbox');
        PlatformSetting::set('currency', 'UGX');
        $transaction = $this->createWithdrawal(walletBalance: 30000);

        Http::fake([
            'api.sandbox.pawapay.io/v2/predict-provider' => Http::response([
                'provider' => 'MTN',
                'phoneNumber' => '256771234567',
            ]),
            'api.sandbox.pawapay.io/v2/payouts' => function ($request) {
                return Http::response([
                    'status' => 'ACCEPTED',
                    'payoutId' => $request['payoutId'],
                ]);
            },
            'api.sandbox.pawapay.io/v2/payouts/*' => function ($request, $url) use ($transaction) {
                return Http::response([
                    'status' => 'FAILED',
                    'payoutId' => basename(parse_url($request->url(), PHP_URL_PATH)),
                    'clientReferenceId' => $transaction->reference,
                    'amount' => '10000',
                    'currency' => 'UGX',
                ]);
            },
        ]);

        $service = app(PaymentPayoutService::class);
        $result = $service->approve($transaction, 'pawapay');
        $this->assertTrue($result['ok']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame('40000.00', $transaction->tenant->fresh()->wallet_balance);

        $service->reconcile($transaction->fresh());
        $this->assertSame('40000.00', $transaction->tenant->fresh()->wallet_balance);
    }

    private function createWithdrawal(float $walletBalance = 40000): Transaction
    {
        $tenant = Tenant::create([
            'name' => 'Payout operator',
            'slug' => 'payout-operator',
            'status' => 'active',
            'contact_name' => 'Operator User',
            'wallet_balance' => $walletBalance,
        ]);

        return Transaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => 'WTH-PAYOUT-001',
            'phone' => '0771234567',
            'meta' => ['payout_method' => 'mtn_momo'],
        ]);
    }
}
