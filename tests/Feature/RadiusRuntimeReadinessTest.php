<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\RadiusAccountingService;
use App\Services\RadiusUnavailableException;
use App\Services\SaleFulfillmentService;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiusRuntimeReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_sale_stays_pending_without_exhaustion_or_provider_calls_and_can_recover(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$tenant, $package, $router] = $this->setupRadius();
        $sale = Transaction::create(['tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000,
            'status' => 'pending', 'reference' => 'TEST-PAID-1', 'payment_token_hash' => hash('sha256', 'runtime-test-token'), 'meta' => ['package_id' => $package->id, 'router_id' => $router->id]]);
        $worker = app(SaleFulfillmentService::class);
        // Synthetic verified payment boundary: no payment provider is called.
        $worker->confirm($tenant, $package, '0771234567', $sale);
        for ($i = 0; $i < 12; $i++) {
            DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->update(['next_attempt_at' => null]);
            $this->assertNull($worker->attempt($tenant->id, $sale->id));
        }
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'pending', 'attempts' => 0]);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('vouchers', 0);
        $this->assertDatabaseCount('sessions_log', 0);
        $this->getJson('/api/payments/status', ['X-Payment-Token' => 'runtime-test-token'])
            ->assertStatus(202)->assertJson(['status' => 'fulfillment_pending']);
        Http::assertNothingSent();
        $this->assertNull($worker->attempt($tenant->id + 1000, $sale->id));
        // Explicit operator change to site agent restores the normal path.
        $router->forceFill(['access_backend' => 'site_agent'])->save();
        DB::table('sale_fulfillment_work')->where('transaction_id', $sale->id)->update(['next_attempt_at' => null]);
        $this->assertNotNull($worker->attempt($tenant->id, $sale->id));
        $this->assertDatabaseHas('sale_fulfillment_work', ['transaction_id' => $sale->id, 'status' => 'completed']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_direct_activation_and_policy_calls_fail_closed_for_existing_and_new_sessions(): void
    {
        [$tenant, $package, $router] = $this->setupRadius();
        $batch = VoucherBatch::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'batch_code' => 'TEST-BATCH', 'quantity' => 1, 'status' => 'active']);
        $voucher = Voucher::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'voucher_batch_id' => $batch->id, 'code' => 'TEST-CODE', 'status' => 'unused']);
        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)->activate($tenant, $voucher, Request::create('/'), null, ['router_id' => $router->id]));
            $this->fail('RADIUS activation should be blocked');
        } catch (RadiusUnavailableException $e) {
            $this->assertSame('unused', $voucher->fresh()->status);
        }
        $this->assertDatabaseCount('sessions_log', 0);
        $service = app(RadiusAccountingService::class);
        $this->assertSame(['decision' => 'reject'], $service->authorize('test-nas', 'synthetic-session', 'TEST-CODE'));
        $this->assertSame('radius_unavailable', $service->ingestAccountingEvent([])['reason']);
        // An existing session created before the shutdown cannot be resumed as RADIUS.
        $router->forceFill(['access_backend' => 'site_agent'])->save();
        $session = app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)->activate($tenant, $voucher, Request::create('/'), null, ['router_id' => $router->id]));
        $router->forceFill(['access_backend' => 'radius'])->save();
        $sale = Transaction::create(['tenant_id' => $tenant->id, 'type' => 'voucher_sale', 'amount' => 1000,
            'status' => 'completed', 'reference' => 'LEGACY-PAID', 'payment_token_hash' => hash('sha256', 'legacy-token'),
            'meta' => ['package_id' => $package->id, 'voucher_id' => $voucher->id]]);
        $voucher->forceFill(['sale_transaction_id' => $sale->id])->save();
        $this->getJson('/api/payments/status', ['X-Payment-Token' => 'legacy-token'])
            ->assertStatus(202)->assertJson(['status' => 'activation_unavailable'])->assertJsonMissing(['status' => 'radius_ready']);
        try {
            app(TenantContext::class)->runAs($tenant, fn () => app(VoucherActivationService::class)->activate($tenant, $voucher, Request::create('/')));
            $this->fail('Existing RADIUS session must not be returned as ready');
        } catch (RadiusUnavailableException $e) {
            $this->assertSame('active', $session->fresh()->status);
        }
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        try {
            app(TenantContext::class)->runAs($other, fn () => app(VoucherActivationService::class)->activate($other, $voucher, Request::create('/')));
            $this->fail('Cross-tenant voucher should not resolve');
        } catch (ModelNotFoundException $e) {
            $this->assertSame('redeemed', $voucher->fresh()->status);
        }
    }

    private function setupRadius(): array
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active', 'wallet_balance' => 0, 'commission_rate' => 5]);
        $package = Package::create(['tenant_id' => $tenant->id, 'name' => 'Hour', 'price' => 1000, 'currency' => 'UGX', 'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 10, 'device_limit' => 1, 'is_published' => true]);
        $router = Router::create(['tenant_id' => $tenant->id, 'name' => 'Synthetic router']);
        // Model an existing RADIUS router without invoking the disabled opt-in path.
        $router->forceFill([
            'access_backend' => 'radius',
            'radius_nas_identifier' => 'test-nas',
            'radius_shared_secret' => str_repeat('a', 64),
        ])->save();

        return [$tenant, $package, $router->fresh()];
    }
}
