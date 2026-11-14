<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\SaleFulfillmentService;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
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

    public function test_verified_payment_survives_activation_failure_and_retries_without_provider_reverification(): void
    {
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $this->fakeMtn();
        app(PaymentService::class)->initiatePackagePayment($tenant, $package, '0771234567', $transaction);
        $this->mock(VoucherActivationService::class, function ($mock): void {
            $mock->shouldReceive('activate')->once()->andThrow(new \RuntimeException('simulated activation failure'));
        });
        $this->assertSame('fulfillment_pending', app(PaymentService::class)->reconcilePendingPayment($transaction));
        $this->assertSame('completed', $transaction->fresh()->status);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $transaction->id, 'status' => 'pending', 'attempts' => 1]);
        $this->getJson('/api/payments/status', ['X-Payment-Token' => self::PAYMENT_STATUS_TOKEN])
            ->assertStatus(202)->assertJson(['status' => 'fulfillment_pending']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/collection/v1_0/requesttopay/'.$transaction->fresh()->provider_reference));

        $this->app->forgetInstance(VoucherActivationService::class);
        $this->travel(6)->minutes();
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $transaction->id, 'status' => 'completed', 'attempts' => 2]);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('sessions_log', 1);
        $this->getJson('/api/payments/status', ['X-Payment-Token' => self::PAYMENT_STATUS_TOKEN])
            ->assertOk()->assertJson(['status' => 'completed']);
    }

    public function test_committed_work_recovers_after_crash_before_dispatch_and_expired_lease(): void
    {
        [$tenant, $package, $sale] = $this->createPendingPayment();
        $worker = app(SaleFulfillmentService::class);
        // This is the boundary after provider verification and before any worker/queue call.
        $worker->confirm($tenant, $package, '0771234567', $sale);
        $worker->confirm($tenant, $package, '0771234567', $sale);
        $this->assertDatabaseCount('sale_fulfillment_work', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->update([
            'status' => 'running', 'attempts' => 1, 'lease_token' => 'dead-worker', 'next_attempt_at' => now()->subSecond(),
        ]);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'completed', 'attempts' => 2]);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertSame('950.00', $tenant->fresh()->wallet_balance);
    }

    public function test_retry_is_tenant_scoped_and_never_scans_historic_paid_sales(): void
    {
        [$tenant, $package, $sale] = $this->createPendingPayment();
        app(SaleFulfillmentService::class)->confirm($tenant, $package, '0771234567', $sale);
        $this->assertNull(app(SaleFulfillmentService::class)->attempt($tenant->id + 1000, $sale->id));
        $historic = Transaction::create(['tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000,
            'status' => 'completed', 'reference' => 'ONL-OLD', 'meta' => ['package_id' => $package->id]]);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseMissing('sale_fulfillment_work', ['transaction_id' => $historic->id]);
        $this->assertDatabaseMissing('vouchers', ['sale_transaction_id' => $historic->id]);
    }

    public function test_attempts_are_bounded_and_unconfirmed_access_is_not_active(): void
    {
        [$tenant, $package, $sale] = $this->createPendingPayment();
        $this->getJson('/api/payments/status', ['X-Payment-Token' => self::PAYMENT_STATUS_TOKEN])
            ->assertStatus(202)->assertJson(['status' => 'pending']);
        $this->postJson('/api/payments/activate', [], ['X-Payment-Token' => self::PAYMENT_STATUS_TOKEN,
            'X-Idempotency-Key' => 'durability-test-activation-key-001'])->assertStatus(409);
        app(SaleFulfillmentService::class)->confirm($tenant, $package, '0771234567', $sale);
        DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->update([
            'status' => 'running', 'attempts' => 10, 'lease_token' => 'dead-worker', 'next_attempt_at' => now()->subSecond(),
        ]);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'exhausted', 'attempts' => 10]);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertSame('completed', $sale->fresh()->status);
    }

    private function fakeMtn(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::MTN_URL.'/collection/token/' => Http::response(['access_token' => 'mtn-access-token']),
            self::MTN_URL.'/collection/v1_0/requesttopay' => Http::response([], 202),
            self::MTN_URL.'/collection/v1_0/requesttopay/*' => Http::response([
                'amount' => '1000', 'currency' => 'EUR', 'externalId' => 'ONL-TEST-001', 'status' => 'SUCCESSFUL',
            ]),
        ]);
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
