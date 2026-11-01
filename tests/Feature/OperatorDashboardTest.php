<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_tenant_scoped_metrics_and_recent_packages(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'Other Wi-Fi', 'slug' => 'other', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $context = app(TenantContext::class);
        $context->runAs($tenant, function (): void {
            $package = Package::create([
                'name' => 'Daily Pass',
                'price' => 1000,
                'currency' => 'UGX',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 10,
                'device_limit' => 1,
                'is_published' => true,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $package->id,
                'batch_code' => 'ACME-DASH',
                'quantity' => 1,
            ]);
            Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'ACME-DASH-01',
                'status' => 'redeemed',
                'redeemed_at' => now(),
            ]);

            $usdPackage = Package::create([
                'name' => 'International Pass',
                'price' => 250,
                'currency' => 'USD',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 10,
                'device_limit' => 1,
            ]);
            $usdBatch = VoucherBatch::create([
                'package_id' => $usdPackage->id,
                'batch_code' => 'ACME-USD-DASH',
                'quantity' => 1,
            ]);
            Voucher::create([
                'voucher_batch_id' => $usdBatch->id,
                'package_id' => $usdPackage->id,
                'code' => 'ACME-USD-DASH-01',
                'status' => 'redeemed',
                'redeemed_at' => now(),
            ]);

            $onlineCustomer = Customer::create(['name' => 'Online Customer', 'phone' => '+256700000001']);
            Customer::create(['name' => 'Offline Customer', 'phone' => '+256700000002']);

            Session::create([
                'customer_id' => $onlineCustomer->id,
                'package_id' => $package->id,
                'status' => 'active',
                'started_at' => now()->subMinutes(5),
                'expires_at' => now()->addHour(),
                'bytes_in' => 1_073_741_824,
                'bytes_out' => 0,
            ]);

            Router::create(['name' => 'Acme Router', 'status' => 'online']);
        });

        $context->runAs($otherTenant, function (): void {
            $package = Package::create([
                'name' => 'Other Tenant Package',
                'price' => 9000,
                'currency' => 'UGX',
                'duration_value' => 1,
                'duration_unit' => 'days',
                'speed_limit_mbps' => 10,
                'device_limit' => 1,
            ]);
            $batch = VoucherBatch::create([
                'package_id' => $package->id,
                'batch_code' => 'OTHER-DASH',
                'quantity' => 1,
            ]);
            Voucher::create([
                'voucher_batch_id' => $batch->id,
                'package_id' => $package->id,
                'code' => 'OTHER-DASH-01',
                'status' => 'redeemed',
                'redeemed_at' => now(),
            ]);

            Customer::create(['name' => 'Other Tenant Customer']);
            Router::create(['name' => 'Other Tenant Router', 'status' => 'online']);
        });

        $response = $this->actingAs($operator)->get('http://acme.localhost/operator/dashboard?currency=UGX');

        $response->assertOk()
            ->assertSee('Daily Pass')
            ->assertSee('Revenue trends')
            ->assertSee('User status')
            ->assertSee('Recent packages')
            ->assertSee('chart.umd.min.js')
            ->assertDontSee('Other Tenant Package')
            ->assertDontSee('Other Tenant Customer')
            ->assertViewHas('todayRevenue', 1000.0)
            ->assertViewHas('totalCustomers', 2)
            ->assertViewHas('activeSessions', 1)
            ->assertViewHas('offlineCustomers', 1)
            ->assertViewHas('totalRouters', 1)
            ->assertViewHas('onlineRouters', 1)
            ->assertViewHas('dataUsageGb', 1.0)
            ->assertViewHas('chartData', fn ($chartData) => $chartData->count() === 7 && $chartData->last()['revenue'] === 1000.0);

        $this->actingAs($operator)
            ->get('http://acme.localhost/operator/dashboard?currency=USD')
            ->assertOk()
            ->assertViewHas('currency', 'USD')
            ->assertViewHas('todayRevenue', 250.0);
    }

    public function test_dashboard_defaults_to_a_supported_currency_and_handles_empty_tenant(): void
    {
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Empty Wi-Fi', 'slug' => 'empty', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        $response = $this->actingAs($operator)->get('http://empty.localhost/operator/dashboard?currency=INVALID');

        $response->assertOk()
            ->assertSee('UGX')
            ->assertViewHas('todayRevenue', 0.0)
            ->assertViewHas('onlinePercentage', 0)
            ->assertViewHas('chartData', fn ($chartData) => $chartData->count() === 7);
    }

    public function test_online_percentage_uses_active_sessions_when_customer_records_are_missing(): void
    {
        Role::firstOrCreate(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Hotspot Wi-Fi', 'slug' => 'hotspot', 'status' => 'active']);
        $operator = User::factory()->create(['tenant_id' => $tenant->id]);
        $operator->assignRole('operator_admin');

        app(TenantContext::class)->runAs($tenant, function (): void {
            foreach (range(1, 5) as $sessionNumber) {
                Session::create([
                    'status' => 'active',
                    'started_at' => now()->subMinutes($sessionNumber),
                    'expires_at' => now()->addHour(),
                    'bytes_in' => 0,
                    'bytes_out' => 0,
                ]);
            }
        });

        $this->actingAs($operator)
            ->get('http://hotspot.localhost/operator/dashboard')
            ->assertOk()
            ->assertViewHas('totalCustomers', 0)
            ->assertViewHas('totalUsers', 5)
            ->assertViewHas('activeSessions', 5)
            ->assertViewHas('offlineCustomers', 0)
            ->assertViewHas('onlinePercentage', 100)
            ->assertSee('>100%</span>', false)
            ->assertSee('>5</strong>', false);
    }
}
