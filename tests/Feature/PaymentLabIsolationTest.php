<?php

namespace Tests\Feature;

use App\Exceptions\RefundException;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AirtelMoneyService;
use App\Services\FlutterwaveService;
use App\Services\FlutterwaveWithdrawalService;
use App\Services\MtnMomoService;
use App\Services\PaymentSandboxGuard;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLabIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.lab_mode' => true, 'payments.simulation_enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_staging_is_guarded_even_when_both_switches_are_off(): void
    {
        config(['payments.lab_mode' => false, 'payments.sandbox_only' => false]);
        $original = app()['env'];
        try {
            app()['env'] = 'staging';
            $this->assertTrue(PaymentSandboxGuard::enabled());
            PlatformSetting::set('mtn_environment', 'production');
            $this->expectException(\RuntimeException::class);
            PaymentSandboxGuard::check('mtn', 'https://sandbox.momodeveloper.mtn.com');
        } finally {
            app()['env'] = $original;
        }
    }

    public function test_mtn_and_airtel_http_requests_do_not_follow_redirects_and_reject_3xx_tokens(): void
    {
        PlatformSetting::setSecret('mtn_subscription_key', 'fake');
        PlatformSetting::setSecret('mtn_api_user', 'fake');
        PlatformSetting::setSecret('mtn_api_key', 'fake');
        PlatformSetting::setSecret('airtel_client_id', 'fake');
        PlatformSetting::setSecret('airtel_client_secret', 'fake');
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen[] = [$request->url(), $options['allow_redirects'] ?? null];

            return Http::response(['access_token' => 'must-not-be-used'], 302, ['Location' => 'https://live.invalid/steal']);
        });
        foreach ([app(MtnMomoService::class), app(AirtelMoneyService::class)] as $service) {
            try {
                $service->verify('ref');
                $this->fail('A 302 token response must not be used.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unsuccessful', $e->getMessage());
            }
        }
        $this->assertCount(2, $optionsSeen);
        $this->assertSame(false, $optionsSeen[0][1]);
        $this->assertSame(false, $optionsSeen[1][1]);
        Http::assertSentCount(2);
    }

    public function test_all_unrelated_provider_paths_and_withdrawal_simulation_are_blocked(): void
    {
        Http::fake();
        PlatformSetting::setSecret('flutterwave_secret_key', 'fake-live-secret');
        $withdrawal = new Transaction(['type' => 'withdrawal', 'status' => 'pending']);
        $service = app(FlutterwaveWithdrawalService::class);
        $this->assertFalse($service->approve($withdrawal)['ok']);
        $this->assertFalse($service->handleWebhook([])['ok']);
        $this->assertSame([
            'state' => 'unavailable',
            'message' => 'Withdrawals are disabled in the payment sandbox lab.',
        ], $service->reconcileStuck($withdrawal));
        $this->assertFalse(SmsService::send('+256700000000', 'test')['success']);
        $this->assertFalse(SmsService::sendViaTwilio('+256700000000', 'test')['success']);
        $this->assertFalse(SmsService::sendViaAfricasTalking('+256700000000', 'test')['success']);
        try {
            app(FlutterwaveService::class)->charge([]);
            $this->fail('Flutterwave should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        try {
            app(RefundService::class)->approve(new Refund, new User, 'fake');
            $this->fail('Manual refund payout approval should be blocked.');
        } catch (RefundException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }
        Http::assertNothingSent();
        $this->get('/simulate-momo-success/irrelevant')->assertNotFound();
    }

    public function test_lab_disables_collection_simulation_and_blocks_unverified_checkout_after_recording_order(): void
    {
        [$tenant, $package, $transaction] = $this->payment();
        PlatformSetting::set('pesapal_consumer_key', 'fake');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'fake');
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen[] = [$request->url(), $options['allow_redirects'] ?? null];

            return str_contains($request->url(), 'RequestToken')
                ? Http::response(['token' => 'fake'])
                : Http::response(['order_tracking_id' => 'tracking-lab', 'redirect_url' => 'https://cybqa.pesapal.com/checkout']);
        });
        $service = app(PaymentService::class);
        $result = $service->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $this->assertSame('pending', $result['status']);
        $this->assertArrayNotHasKey('redirect_url', $result);
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('tracking-lab', $transaction->fresh()->provider_reference);
        $this->assertNull($transaction->fresh()->meta['pesapal_redirect_url']);
        $this->assertSame('pending', $service->initiatePackagePayment($tenant, $package, '0771234567', $transaction->fresh())['status']);
        $this->assertCount(2, $optionsSeen);
        $this->assertSame([false, false], array_column($optionsSeen, 1));
        Http::assertSentCount(2);
    }

    public function test_pesapal_redirecting_order_response_is_ambiguous_and_never_retried(): void
    {
        [$tenant, $package, $transaction] = $this->payment();
        PlatformSetting::set('pesapal_consumer_key', 'fake');
        PlatformSetting::setSecret('pesapal_consumer_secret', 'fake');
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen[] = $options['allow_redirects'] ?? null;

            return str_contains($request->url(), 'RequestToken')
                ? Http::response(['token' => 'fake'])
                : Http::response(['redirect_url' => 'https://live.invalid/checkout'], 302, ['Location' => 'https://live.invalid/steal']);
        });
        $service = app(PaymentService::class);
        $this->assertSame('pending', $service->initiatePackagePayment($tenant, $package, '0771234567', $transaction)['status']);
        $this->assertSame('unknown', $transaction->fresh()->meta['provider_submission']);
        $this->assertSame('pending', $service->initiatePackagePayment($tenant, $package, '0771234567', $transaction->fresh())['status']);
        $this->assertSame([false, false], $optionsSeen);
        Http::assertSentCount(2);
    }

    public function test_pesapal_webhook_rejects_redirecting_authentication(): void
    {
        $optionsSeen = [];
        Http::fake(function ($request, $options) use (&$optionsSeen) {
            $optionsSeen[] = $options['allow_redirects'] ?? null;

            return Http::response(['token' => 'fake'], 302, ['Location' => 'https://live.invalid']);
        });
        $this->postJson(route('api.webhooks.pesapal'), ['OrderTrackingId' => 'test'])
            ->assertStatus(502)->assertJsonPath('status', 'auth_failed');
        $this->assertSame([false], $optionsSeen);
        Http::assertSentCount(1);
    }

    private function payment(): array
    {
        $tenant = Tenant::create(['name' => 'Lab', 'slug' => 'lab', 'status' => 'active', 'wallet_balance' => 0, 'commission_rate' => 5]);
        $package = Package::create(['tenant_id' => $tenant->id, 'name' => 'Lab', 'price' => 1000, 'currency' => 'UGX', 'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 10, 'device_limit' => 1]);
        $transaction = Transaction::create(['tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000, 'status' => 'pending', 'reference' => 'LAB-1', 'phone' => '0771234567', 'meta' => ['package_id' => $package->id, 'phone' => '0771234567']]);

        return [$tenant, $package, $transaction];
    }
}
