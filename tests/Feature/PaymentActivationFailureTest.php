<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentActivationFailureTest extends TestCase
{
    use RefreshDatabase;

    private const MTN_URL = 'https://sandbox.momodeveloper.mtn.com';

    private const PAYMENT_STATUS_TOKEN = 'test-payment-status-token';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::setSecret('mtn_subscription_key', 'mtn-subscription-test');
        PlatformSetting::setSecret('mtn_api_user', 'mtn-api-user-test');
        PlatformSetting::setSecret('mtn_api_key', 'mtn-api-key-test');
        config(['payments.simulation_enabled' => false]);
    }

    public function test_verified_payment_activation_failure_currently_rolls_back_then_can_be_retried(): void
    {
        [$tenant, $transaction] = $this->verifiedPaymentWithActivationFailure();

        // Characterization of the current bug, NOT a payment-durability fix.
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('sessions_log', 0);
        $this->assertArrayNotHasKey('voucher_id', $transaction->fresh()->meta);

        // A later verified retry can currently fulfill exactly once.
        $this->app->forgetInstance(VoucherActivationService::class);
        $service = app(PaymentService::class);
        $this->assertSame('completed', $service->reconcilePendingPayment($transaction->fresh()));
        $this->assertSame('completed', $service->reconcilePendingPayment($transaction->fresh()));
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('sessions_log', 1);
    }

    public function test_future_verified_payment_is_durable_when_activation_fails(): void
    {
        $this->markTestSkipped('Known defect: verified payment fulfillment currently rolls back on activation failure; no production fix in this PR.');

        [$tenant, $transaction] = $this->verifiedPaymentWithActivationFailure();
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertArrayHasKey('voucher_id', $transaction->fresh()->meta);
    }

    private function verifiedPaymentWithActivationFailure(): array
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
            self::MTN_URL.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1000', 'currency' => 'EUR', 'externalId' => 'ONL-TEST-001', 'status' => 'SUCCESSFUL',
            ]),
        ]);
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $transaction->refresh();

        $this->mock(VoucherActivationService::class, function ($mock): void {
            $mock->shouldReceive('activate')->once()->andThrow(new \RuntimeException('simulated activation failure'));
        });
        try {
            app(PaymentService::class)->reconcilePendingPayment($transaction);
            $this->fail('Expected activation to fail after provider verification.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated activation failure', $exception->getMessage());
        }
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/collection/v1_0/requesttopay/'.$transaction->provider_reference));

        return [$tenant, $transaction];
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
