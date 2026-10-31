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
}
