<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\RadiusUnavailableException;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PortalReadinessSyntheticTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $t = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active']);
        $p = Package::create(['tenant_id' => $t->id, 'name' => 'Hour', 'price' => 1000, 'currency' => 'UGX', 'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 10, 'device_limit' => 1, 'is_published' => true]);
        $b = VoucherBatch::create(['tenant_id' => $t->id, 'package_id' => $p->id, 'batch_code' => 'TEST', 'quantity' => 1, 'status' => 'active']);
        $v = Voucher::create(['tenant_id' => $t->id, 'package_id' => $p->id, 'voucher_batch_id' => $b->id, 'code' => 'TEST-CODE', 'status' => 'unused']);

        return [$t, $p, $v];
    }

    public function test_ambiguous_missing_and_legacy_routers_fail_closed(): void
    {
        [$t,$p,$v] = $this->fixture();
        $activate = fn (array $ctx = []) => app(TenantContext::class)->runAs($t, fn () => app(VoucherActivationService::class)->activate($t, $v, Request::create('/'), null, $ctx));
        try {
            $activate();
            $this->fail('Missing router must fail closed');
        } catch (RadiusUnavailableException $e) {
            $this->assertSame('unused', $v->fresh()->status);
        }
        $r = Router::create(['tenant_id' => $t->id, 'name' => 'duplicate']);
        $r->forceFill(['access_backend' => 'site_agent'])->saveOrFail();
        Router::create(['tenant_id' => $t->id, 'name' => 'duplicate'])->forceFill(['access_backend' => 'site_agent'])->saveOrFail();
        foreach ([[], ['router' => 'duplicate'], ['router_id' => 99999]] as $context) {
            try {
                $activate($context);
                $this->fail('Ambiguous or invalid router must fail');
            } catch (RadiusUnavailableException $e) {
                $this->assertSame('unused', $v->fresh()->status);
            }
        }
        $this->assertDatabaseCount('sessions_log', 0);
        try {
            $activate(['router_id' => $r->id]);
            $this->fail('A legacy non-RADIUS router must not activate access.');
        } catch (RadiusUnavailableException $e) {
            $this->assertStringContainsString('unsupported access backend', $e->getMessage());
        }
        $this->assertSame('unused', $v->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_legacy_router_never_activates_a_voucher(): void
    {
        [$t,$p,$v] = $this->fixture();
        $r = Router::create(['tenant_id' => $t->id, 'name' => 'synthetic']);
        $r->forceFill(['access_backend' => 'site_agent'])->saveOrFail();
        try {
            app(TenantContext::class)->runAs($t, fn () => app(VoucherActivationService::class)
                ->activate($t, $v, Request::create('/'), null, ['router_id' => $r->id]));
            $this->fail('A legacy non-RADIUS router must not activate a voucher.');
        } catch (RadiusUnavailableException $e) {
            $this->assertStringContainsString('unsupported access backend', $e->getMessage());
        }
        $this->assertSame('unused', $v->fresh()->status);
        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_invalid_payment_token_clears_stale_success_before_readiness_check(): void
    {
        [$t] = $this->fixture();
        $this->withSession(['payment_token' => 'invalid', 'voucher_tenant_id' => $t->id, 'voucher_code' => 'STALE', 'mikrotik_url' => 'http://example.invalid', 'radius_ready' => true])
            ->get(route('portal.success', ['tenant' => $t->slug]))->assertRedirect()->assertSessionMissing('payment_token')->assertSessionMissing('voucher_code')->assertSessionMissing('mikrotik_url')->assertSessionMissing('radius_ready');
    }
}
