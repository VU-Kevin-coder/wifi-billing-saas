<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
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

    public function test_verified_pawapay_callback_processes_even_when_radius_is_unavailable(): void
    {
        [$tenant, $package] = $this->fixture();
        $this->router($tenant, 'radius');
        PlatformSetting::setSecret('payment.pawapay.api_token', 'pawa-test-token');
        PlatformSetting::set('payment.pawapay.environment', 'sandbox');
        $sale = Transaction::create([
            'tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000,
            'status' => 'pending', 'reference' => 'SYNTHETIC-PAID', 'provider_reference' => 'synthetic-tracking',
            'payment_token_hash' => hash('sha256', 'synthetic-token'),
            'phone' => '0771234567',
            'meta' => [
                'package_id' => $package->id,
                'phone' => '0771234567',
                'payment_provider' => 'pawapay',
                'provider_transaction_id' => 'synthetic-tracking',
            ],
        ]);
        Http::fake([
            'https://api.sandbox.pawapay.io/v2/deposits/synthetic-tracking' => Http::response([
                'depositId' => 'synthetic-tracking',
                'clientReferenceId' => $sale->reference,
                'currency' => 'UGX',
                'amount' => '1000',
                'status' => 'COMPLETED',
            ]),
        ]);
        Http::preventStrayRequests();
        $this->postJson('/api/webhooks/pawapay', ['depositId' => 'synthetic-tracking'])
            ->assertOk()
            ->assertJson(['status' => 'fulfillment_pending']);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseCount('transactions', 1);
    }
}
