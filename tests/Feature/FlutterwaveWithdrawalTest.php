<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FlutterwaveWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlutterwaveWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private const API_URL = 'https://api.flutterwave.com/v3';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::setSecret('flutterwave_secret_key', 'test-secret-key');
        PlatformSetting::set('currency', 'UGX');
    }

    public function test_admin_approval_submits_a_transfer_once_and_waits_for_verified_completion(): void
    {
        $transaction = $this->createPendingWithdrawal();
        $transferVerification = Http::sequence()
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 9911,
                    'reference' => $transaction->reference,
                    'amount' => 10000,
                    'currency' => 'UGX',
                    'status' => 'NEW',
                ],
            ])
            ->push([
                'status' => 'success',
                'data' => [
                    'id' => 9911,
                    'reference' => $transaction->reference,
                    'amount' => 10000,
                    'currency' => 'UGX',
                    'status' => 'SUCCESSFUL',
                ],
            ]);
        Http::fake([
            self::API_URL.'/transfers' => Http::response([
                'status' => 'success',
                'data' => ['id' => 9911, 'status' => 'NEW'],
            ]),
            self::API_URL.'/transfers/9911' => $transferVerification,
        ]);

        $service = app(FlutterwaveWithdrawalService::class);
        $result = $service->approve($transaction);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['submitted']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('9911', $transaction->fresh()->meta['flutterwave_transfer_id']);

        $duplicate = $service->approve($transaction->fresh());
        $this->assertFalse($duplicate['submitted']);
        Http::assertSentCount(2);

        $result = $service->handleWebhook([
            'id' => 9911,
            'reference' => $transaction->reference,
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $transaction->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_failed_verified_transfer_refunds_the_operator_wallet_once(): void
    {
        $transaction = $this->createPendingWithdrawal([
            'meta' => [
                'flutterwave_transfer_id' => '9922',
                'flutterwave_transfer_submission' => 'submitted',
            ],
        ]);
        Http::fake([
            self::API_URL.'/transfers/9922' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 9922,
                    'reference' => $transaction->reference,
                    'amount' => 10000,
                    'currency' => 'UGX',
                    'status' => 'FAILED',
                ],
            ]),
        ]);

        $service = app(FlutterwaveWithdrawalService::class);
        $result = $service->handleWebhook([
            'id' => 9922,
            'reference' => $transaction->reference,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame('50000.00', $transaction->tenant->fresh()->wallet_balance);

        $service->handleWebhook(['id' => 9922, 'reference' => $transaction->reference]);
        $this->assertSame('50000.00', $transaction->tenant->fresh()->wallet_balance);
    }

    public function test_transfer_without_provider_id_is_locked_against_duplicate_submission(): void
    {
        $transaction = $this->createPendingWithdrawal();
        Http::fake([
            self::API_URL.'/transfers' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'NEW'],
            ]),
        ]);

        $service = app(FlutterwaveWithdrawalService::class);
        $result = $service->approve($transaction);

        $this->assertFalse($result['ok']);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('unknown', $transaction->fresh()->meta['flutterwave_transfer_submission']);

        $service->approve($transaction->fresh());
        Http::assertSentCount(1);
    }

    private function createPendingWithdrawal(array $overrides = []): Transaction
    {
        $tenant = Tenant::create([
            'name' => 'Operator',
            'slug' => 'operator',
            'status' => 'active',
            'contact_name' => 'Operator User',
            'wallet_balance' => 40000,
        ]);

        return Transaction::create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => 'WTH-TEST-001',
            'phone' => '0771234567',
            'meta' => [],
            'description' => 'Withdrawal to 256771234567',
        ], $overrides));
    }
}
