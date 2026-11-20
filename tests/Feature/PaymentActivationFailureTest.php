<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\SaleFulfillmentService;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentActivationFailureTest extends TestCase
{
    use RefreshDatabase;

    private const PAWAPAY_URL = 'https://api.sandbox.pawapay.io';

    private const PAYMENT_STATUS_TOKEN = 'test-payment-status-token';

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::setSecret('payment.pawapay.api_token', 'pawapay-test-token');
        PlatformSetting::set('payment.active_gateway', 'pawapay');
        config(['payments.simulation_enabled' => false, 'radius.enabled' => true]);
    }

    public function test_verified_payment_survives_activation_failure_and_retries_without_provider_reverification(): void
    {
        [$tenant, $package, $transaction] = $this->createPendingPayment();
        $this->fakePawaPay();
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
            && str_ends_with($request->url(), '/v2/deposits/'.$transaction->fresh()->provider_reference));

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
            ->assertStatus(202)->assertJson(['status' => 'radius_ready']);
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
            'status' => 'running', 'attempts' => 1, 'lease_token' => '00000000-0000-4000-8000-000000000001', 'next_attempt_at' => now()->subSecond(),
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
            'status' => 'running', 'attempts' => 10, 'lease_token' => '00000000-0000-4000-8000-000000000001', 'next_attempt_at' => now()->subSecond(),
        ]);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'exhausted', 'attempts' => 10]);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_final_failure_is_logged_as_exhausted_and_inspectable_without_retrying(): void
    {
        [$tenant, $package, $sale] = $this->createPendingPayment();
        app(SaleFulfillmentService::class)->confirm($tenant, $package, '0771234567', $sale);
        DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->update(['attempts' => 9]);
        $this->mock(VoucherActivationService::class, function ($mock): void {
            $mock->shouldReceive('activate')->once()->andThrow(new \RuntimeException('private details'));
        });
        Log::spy();
        $this->assertNull(app(SaleFulfillmentService::class)->attempt($tenant->id, $sale->id));
        Log::shouldHaveReceived('error')->with('Sale fulfillment retries exhausted.', [
            'tenant_id' => $tenant->id, 'transaction_id' => $sale->id,
        ])->once();
        $this->assertDatabaseHas('sale_fulfillment_work', [
            'transaction_id' => $sale->id, 'status' => 'exhausted', 'attempts' => 10,
            'next_attempt_at' => null, 'lease_token' => null, 'last_error_class' => \RuntimeException::class,
        ]);
        $work = DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->first();
        $this->artisan('payments:inspect-exhausted', ['tenant' => $tenant->id])
            ->expectsOutput("Tenant {$tenant->id}: 1 exhausted work item(s); showing 1.")
            ->expectsTable(['Work ID', 'Transaction ID', 'Attempts', 'Last error class', 'Updated at'], [
                [(string) $work->id, (string) $sale->id, '10', \RuntimeException::class, $work->updated_at],
            ])->assertExitCode(0);
        $this->artisan('payments:inspect-exhausted', ['tenant' => $tenant->id + 1000])
            ->expectsOutput('Tenant '.($tenant->id + 1000).': 0 exhausted work item(s); showing 0.')
            ->assertExitCode(0);
        $this->artisan('payments:inspect-exhausted', ['tenant' => $tenant->id, '--limit' => 101])->assertExitCode(2);
        $this->artisan('payments:retry-fulfillment')->assertExitCode(0);
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'exhausted', 'attempts' => 10]);
        $this->assertDatabaseCount('vouchers', 0);
    }

    private function fakePawaPay(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v2/predict-provider')) {
                return Http::response(['provider' => 'MTN_UGA', 'phoneNumber' => '256771234567']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/deposits')) {
                $transaction = Transaction::withoutTenancy()->where('reference', 'ONL-TEST-001')->firstOrFail();

                return Http::response([
                    'status' => 'ACCEPTED',
                    'depositId' => $transaction->provider_reference,
                ]);
            }

            $transaction = Transaction::withoutTenancy()->where('reference', 'ONL-TEST-001')->firstOrFail();

            return Http::response([
                'depositId' => $transaction->provider_reference,
                'clientReferenceId' => $transaction->reference,
                'amount' => '1000',
                'currency' => 'UGX',
                'status' => 'COMPLETED',
            ]);
        });
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
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'Synthetic RADIUS router']);
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'nas-'.$router->id,
            'radius_shared_secret' => str_repeat('a', 64),
        ])->saveOrFail();
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
