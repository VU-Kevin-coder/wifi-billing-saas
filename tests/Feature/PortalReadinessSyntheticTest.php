<?php
namespace Tests\Feature;

use App\Models\{Tenant, Package, Router, Voucher, VoucherBatch, Transaction};
use App\Services\{VoucherActivationService, RadiusUnavailableException};
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalReadinessSyntheticTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $t = Tenant::create(['name'=>'Test', 'slug'=>'test', 'status'=>'active']);
        $p = Package::create(['tenant_id'=>$t->id, 'name'=>'Hour', 'price'=>1000, 'currency'=>'UGX', 'duration_value'=>1, 'duration_unit'=>'hours', 'speed_limit_mbps'=>10, 'device_limit'=>1, 'is_published'=>true]);
        $b = VoucherBatch::create(['tenant_id'=>$t->id, 'package_id'=>$p->id, 'batch_code'=>'TEST', 'quantity'=>1, 'status'=>'active']);
        $v = Voucher::create(['tenant_id'=>$t->id, 'package_id'=>$p->id, 'voucher_batch_id'=>$b->id, 'code'=>'TEST-CODE', 'status'=>'unused']);
        return [$t,$p,$v];
    }

    public function test_ambiguous_and_missing_router_do_not_activate_but_explicit_site_agent_does(): void
    {
        [$t,$p,$v] = $this->fixture();
        config(['site_agent.required'=>false]);
        $activate = fn (array $ctx = []) => app(TenantContext::class)->runAs($t, fn () => app(VoucherActivationService::class)->activate($t, $v, Request::create('/'), null, $ctx));
        try { $activate(); $this->fail('Missing router must fail closed'); } catch (RadiusUnavailableException $e) { $this->assertSame('unused', $v->fresh()->status); }
        $r = Router::create(['tenant_id'=>$t->id, 'name'=>'duplicate']);
        $r->forceFill(['access_backend'=>'site_agent'])->saveOrFail();
        Router::create(['tenant_id'=>$t->id, 'name'=>'duplicate'])->forceFill(['access_backend'=>'site_agent'])->saveOrFail();
        foreach ([[], ['router'=>'duplicate'], ['router_id'=>99999]] as $context) {
            try { $activate($context); $this->fail('Ambiguous or invalid router must fail'); } catch (RadiusUnavailableException $e) { $this->assertSame('unused', $v->fresh()->status); }
        }
        $this->assertDatabaseCount('sessions_log', 0);
        $session = $activate(['router_id'=>$r->id]);
        $this->assertSame($r->id, $session->router_id);
    }

    public function test_missing_router_never_reports_paid_access_ready(): void
    {
        [$t,$p,$v] = $this->fixture();
        $r = Router::create(['tenant_id'=>$t->id, 'name'=>'synthetic']);
        $r->forceFill(['access_backend'=>'site_agent'])->saveOrFail();
        $s = app(TenantContext::class)->runAs($t, fn () => app(VoucherActivationService::class)->activate($t, $v, Request::create('/'), null, ['router_id'=>$r->id]));
        $sale = Transaction::create(['tenant_id'=>$t->id, 'type'=>'voucher_sale', 'amount'=>1000, 'status'=>'completed', 'reference'=>'SYNTH', 'payment_token_hash'=>hash('sha256','synthetic-token'), 'meta'=>['voucher_id'=>$v->id]]);
        $v->forceFill(['sale_transaction_id'=>$sale->id])->save();
        DB::table('sessions_log')->where('id',$s->id)->update(['router_id'=>null]);
        $this->getJson('/api/payments/status', ['X-Payment-Token'=>'synthetic-token'])->assertStatus(202)->assertJson(['status'=>'activation_unavailable'])->assertJsonMissing(['voucher_code'=>'TEST-CODE']);
        $this->postJson('/api/payments/activate', [], ['X-Payment-Token'=>'synthetic-token', 'X-Idempotency-Key'=>'synthetic-test-key-0001'])->assertStatus(409)->assertJson(['status'=>'activation_failed']);
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_invalid_payment_token_clears_stale_success_before_readiness_check(): void
    {
        [$t] = $this->fixture();
        $this->withSession(['payment_token'=>'invalid', 'voucher_tenant_id'=>$t->id, 'voucher_code'=>'STALE', 'mikrotik_url'=>'http://example.invalid', 'radius_ready'=>true])
            ->get(route('portal.success', ['tenant'=>$t->slug]))->assertRedirect()->assertSessionMissing('payment_token')->assertSessionMissing('voucher_code')->assertSessionMissing('mikrotik_url')->assertSessionMissing('radius_ready');
    }
}
