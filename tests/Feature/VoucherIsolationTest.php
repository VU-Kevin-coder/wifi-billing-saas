<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $acme;
    protected Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
    }

    public function test_vouchers_and_batches_are_isolated(): void
    {
        $context = app(TenantContext::class);

        // Seed Acme's vouchers
        $context->runAs($this->acme, function () {
            $pkg = Package::create([
                'name'            => 'Acme Pkg',
                'price'           => 1000,
                'duration_value'  => 1,
                'duration_unit'   => 'days',
                'speed_limit_mbps'=> 5,
                'device_limit'    => 1,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $pkg->id,
                'batch_code' => 'BATCH-ACM',
                'quantity'   => 1,
            ]);
            Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id'       => $pkg->id,
                'code'             => 'ACME-1234',
                'status'           => 'unused',
            ]);
        });

        // Seed Beta's vouchers
        $context->runAs($this->beta, function () {
            $pkg = Package::create([
                'name'            => 'Beta Pkg',
                'price'           => 5000,
                'duration_value'  => 7,
                'duration_unit'   => 'days',
                'speed_limit_mbps'=> 10,
                'device_limit'    => 2,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $pkg->id,
                'batch_code' => 'BATCH-BET',
                'quantity'   => 1,
            ]);
            Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id'       => $pkg->id,
                'code'             => 'BETA-5678',
                'status'           => 'unused',
            ]);
        });

        // Acme sees only its own data
        $context->runAs($this->acme, function () {
            $this->assertCount(1, Voucher::all());
            $this->assertEquals('ACME-1234', Voucher::first()->code);
            $this->assertCount(1, VoucherBatch::all());
            $this->assertEquals('BATCH-ACM', VoucherBatch::first()->batch_code);
        });

        // Beta sees only its own data
        $context->runAs($this->beta, function () {
            $this->assertCount(1, Voucher::all());
            $this->assertEquals('BETA-5678', Voucher::first()->code);
            $this->assertCount(1, VoucherBatch::all());
            $this->assertEquals('BATCH-BET', VoucherBatch::first()->batch_code);
        });
    }

    public function test_redeemed_voucher_creates_active_session_and_appears_in_live_sessions(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);

        $operator = \App\Models\User::factory()->create([
            'tenant_id' => $this->acme->id,
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
        ]);
        $operator->assignRole('operator_admin');

        $context = app(TenantContext::class);
        $voucher = $context->runAs($this->acme, function () {
            $pkg = Package::create([
                'name'            => '1 Hour Pass',
                'price'           => 1000,
                'duration_value'  => 1,
                'duration_unit'   => 'hours',
                'speed_limit_mbps'=> 5,
                'device_limit'    => 1,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $pkg->id,
                'batch_code' => 'BATCH-TEST',
                'quantity'   => 1,
            ]);
            return Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id'       => $pkg->id,
                'code'             => 'TEST-PASS-99',
                'status'           => 'unused',
            ]);
        });

        // 1. Redeem voucher through captive portal
        $response = $this->post("http://acme.localhost/portal/redeem", [
            'voucher_code' => 'TEST-PASS-99',
        ]);

        $response->assertRedirect("http://acme.localhost/portal/success");

        // Voucher should be marked redeemed
        $voucher->refresh();
        $this->assertEquals('redeemed', $voucher->status);

        // 2. Active session must exist in database for this voucher
        $session = \App\Models\Session::where('voucher_id', $voucher->id)->first();
        $this->assertNotNull($session);
        $this->assertEquals('active', $session->status);
        $this->assertEquals($this->acme->id, $session->tenant_id);

        // 3. Live sessions UI must show this session
        $sessionsUiResponse = $this->actingAs($operator)
            ->get("http://acme.localhost/operator/sessions");

        $sessionsUiResponse->assertStatus(200);
        $sessionsUiResponse->assertSee('TEST-PASS-99');
        $sessionsUiResponse->assertSee('Hotspot');
        $sessionsUiResponse->assertSee('1 Hour Pass');
    }
}
