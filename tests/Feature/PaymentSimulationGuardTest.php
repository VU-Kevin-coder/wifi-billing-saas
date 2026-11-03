<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSimulationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_credentials_with_simulation_disabled_does_not_fulfill_payment(): void
    {
        config(['payments.simulation_enabled' => false]);

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
            'description' => 'Online Purchase: One hour',
        ]);

        $result = app(PaymentService::class)->initiatePackagePayment(
            $tenant,
            $package,
            '0771234567',
            $transaction
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Flutterwave is not configured. Payment could not be initiated.', $result['error']);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame('0.00', $tenant->fresh()->wallet_balance);
        $this->assertDatabaseCount('vouchers', 0);
    }
}
