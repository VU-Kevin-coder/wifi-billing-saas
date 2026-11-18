<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalPurchaseReadinessSyntheticTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'Synthetic', 'slug' => 'synthetic', 'status' => 'active', 'kyc_status' => 'verified']);
        $package = Package::create(['tenant_id' => $tenant->id, 'name' => 'Hour', 'price' => 1000, 'currency' => 'UGX', 'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 10, 'device_limit' => 1]);

        return [$tenant, $package];
    }

    private function router(Tenant $tenant, string $backend): Router
    {
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'synthetic-router']);
        $router->forceFill(['access_backend' => $backend])->save();

        return $router;
    }

    public static function blockedBackends(): array
    {
        return ['no router' => [[]], 'radius only' => [['radius']], 'mixed' => [['site_agent', 'radius']], 'unsupported' => [['site_agent', 'other']]];
    }

    #[DataProvider('blockedBackends')]
    public function test_unsafe_tenant_never_creates_a_sale_or_calls_provider(array $backends): void
    {
        Http::preventStrayRequests();
        [$tenant, $package] = $this->fixture();
        $routers = [];
        foreach ($backends as $backend) {
            $routers[] = $this->router($tenant, $backend);
        }
        $payment = $this->partialMock(PaymentService::class);
        $payment->shouldNotReceive('initiatePackagePayment');
        $payload = ['package_id' => $package->id, 'phone' => '0771234567', '_idempotency_key' => $this->idempotencyKey()];
        if ($routers) {
            $payload['router_id'] = $routers[0]->id;
            $payload['router'] = $routers[0]->name;
        }
        $this->from('/portal/synthetic')->post('/portal/synthetic/purchase', $payload)
            ->assertRedirect('/portal/synthetic')
            ->assertSessionHasErrors(['error' => 'Wi-Fi access is temporarily unavailable. No payment was requested. Please contact the operator.']);
        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_validation_still_runs_before_readiness(): void
    {
        Http::preventStrayRequests();
        [$tenant, $package] = $this->fixture();
        $this->router($tenant, 'radius');
        $payment = $this->partialMock(PaymentService::class);
        $payment->shouldNotReceive('initiatePackagePayment');
        $this->from('/portal/synthetic')->post('/portal/synthetic/purchase', [
            'package_id' => $package->id, 'phone' => 'short', 'router_id' => 999999,
            '_idempotency_key' => $this->idempotencyKey(),
        ])->assertSessionHasErrors(['phone', 'router_id'])->assertSessionDoesntHaveErrors('error');
        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_existing_paid_callback_still_processes_with_radius_unavailable(): void
    {
        [$tenant, $package] = $this->fixture();
        $this->router($tenant, 'radius');
        $sale = Transaction::create([
            'tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000,
            'status' => 'completed', 'reference' => 'SYNTHETIC-PAID', 'provider_reference' => 'synthetic-tracking',
            'payment_token_hash' => hash('sha256', 'synthetic-token'),
            'meta' => ['package_id' => $package->id, 'voucher_id' => 123],
        ]);
        Http::fake([
            '*/Auth/RequestToken' => Http::response(['token' => 'synthetic-token']),
            '*/Transactions/GetTransactionStatus*' => Http::response([
                'payment_status_description' => 'COMPLETED', 'merchant_reference' => $sale->reference,
                'currency' => 'UGX', 'amount' => 1000,
            ]),
        ]);
        Http::preventStrayRequests();
        $this->withSession(['payment_token' => 'synthetic-token'])->get('/portal/synthetic/pesapal/callback?OrderTrackingId=synthetic-tracking&OrderMerchantReference=SYNTHETIC-PAID')
            ->assertRedirect('/portal/synthetic/success');
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_site_agent_only_tenant_can_initiate_and_other_tenants_radius_does_not_block(): void
    {
        Http::preventStrayRequests();
        [$tenant, $package] = $this->fixture();
        $this->router($tenant, 'site_agent');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active', 'kyc_status' => 'verified']);
        $this->router($other, 'radius');
        $payment = $this->partialMock(PaymentService::class);
        $payment->shouldReceive('initiatePackagePayment')->once()->andReturn(['status' => 'pending']);
        $this->post('/portal/synthetic/purchase', ['package_id' => $package->id, 'phone' => '0771234567', '_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('pending', Transaction::withoutGlobalScopes()->firstOrFail()->status);
        Http::assertNothingSent();
    }
}
