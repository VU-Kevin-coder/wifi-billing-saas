<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PlatformPlan;
use App\Models\PlatformSetting;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $message = 'Demo data seeding is disabled in production. Create platform administrators with php artisan platform-admin:create.';
            $this->command?->error($message);

            throw new \RuntimeException($message);
        }

        // ─── 1. ROLES ───────────────────────────────────────────
        $this->call(RoleSeeder::class);

        // ─── 2. PLATFORM SETTINGS ───────────────────────────────
        PlatformSetting::set('platform_name', 'Hamnet');
        PlatformSetting::set('default_commission_rate', 5.00);
        PlatformSetting::firstOrCreate(['key' => 'flutterwave_environment'], ['value' => 'sandbox']);
        PlatformSetting::firstOrCreate(['key' => 'flutterwave_status'], ['value' => 'Not Configured']);

        // ─── 3. PLATFORM PLANS (SaaS Tiers) ─────────────────────
        $starter = PlatformPlan::firstOrCreate(['name' => 'Starter'], [
            'price' => 100000, 'trial_days' => 14, 'max_customers' => 500, 'max_routers' => 2
        ]);
        $growth = PlatformPlan::firstOrCreate(['name' => 'Growth'], [
            'price' => 180000, 'trial_days' => 14, 'max_customers' => 2000, 'max_routers' => 10
        ]);

        // ─── 4. TENANTS (Operators) ─────────────────────────────
        $acme = Tenant::firstOrCreate(['slug' => 'acme'], [
            'name' => 'KampalaLink Internet',
            'legal_name' => 'KampalaLink Telecom Ltd',
            'status' => 'active',
            'kyc_status' => 'verified',
            'contact_name' => 'Sarah Nakato',
            'contact_email' => 'sarah@kampala.net',
            'contact_phone' => '+256 772 411 883',
            'address' => 'Ntinda Complex Road, Level 3',
            'plan' => 'Growth',
            'plan_fee' => 180000,
            'platform_plan_id' => $growth->id,
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addDays(30),
            'wallet_balance' => 0,
            'commission_rate' => 5.00,
        ]);

        $beta = Tenant::firstOrCreate(['slug' => 'beta'], [
            'name' => 'Beta Networks',
            'status' => 'active',
            'kyc_status' => 'pending',
            'contact_name' => 'Peter Okello',
            'plan' => 'Starter',
            'plan_fee' => 100000,
            'platform_plan_id' => $starter->id,
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->addDays(7),
            'wallet_balance' => 0,
            'commission_rate' => 5.00,
        ]);

        // ─── 5. USERS (Platform Admin + Operators) ──────────────
        // Never seed a known password. Set SEED_ADMIN_PASSWORD explicitly
        // when provisioning demo accounts; otherwise a random password is
        // generated and must be reset through the normal flow.
        $seedPassword = (string) env('SEED_ADMIN_PASSWORD', '');
        if ($seedPassword === '') {
            $seedPassword = \Illuminate\Support\Str::password(24);
        }

        $platformAdmin = User::firstOrCreate(['email' => 'platform@hamnet.test'], [
            'name' => 'Hamnet Platform Admin',
            'password' => Hash::make($seedPassword),
            'tenant_id' => null,
            'email_verified_at' => now(),
        ]);
        $platformAdmin->syncRoles(['platform_admin']);

        $acmeAdmin = User::firstOrCreate(['email' => 'admin@acme.test'], [
            'name' => 'Sarah Nakato',
            'password' => Hash::make($seedPassword),
            'tenant_id' => $acme->id,
            'phone' => '+256772411883',
            'email_verified_at' => now(),
        ]);
        $acmeAdmin->syncRoles(['operator_admin']);

        $acmeFinance = User::firstOrCreate(['email' => 'finance@acme.test'], [
            'name' => 'Acme Finance',
            'password' => Hash::make($seedPassword),
            'tenant_id' => $acme->id,
            'email_verified_at' => now(),
        ]);
        $acmeFinance->syncRoles(['operator_finance']);

        $betaAdmin = User::firstOrCreate(['email' => 'admin@beta.test'], [
            'name' => 'Peter Okello',
            'password' => Hash::make($seedPassword),
            'tenant_id' => $beta->id,
            'email_verified_at' => now(),
        ]);
        $betaAdmin->syncRoles(['operator_admin']);

        // ─── 6. SITES ───────────────────────────────────────────
        $sites = [
            ['name' => 'Ntinda Hub', 'location' => 'Ntinda, Kampala'],
            ['name' => 'Wandegeya', 'location' => 'Makerere Hill, Kampala'],
            ['name' => 'Jinja Central', 'location' => 'Central, Jinja'],
            ['name' => 'Bugolobi', 'location' => 'Luthuli Avenue, Kampala'],
        ];
        foreach ($sites as $siteData) {
            Site::firstOrCreate(['tenant_id' => $acme->id, 'name' => $siteData['name']], $siteData);
        }

        // ─── 7. ROUTERS (Including Virtual CHR) ────────────────
        // Virtual CHR Router for testing
        Router::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'CHR-Test'], [
            'management_ip' => 'hamnet-chr',
            'api_username' => 'admin',
            'api_password' => 'admin',
            'api_port' => 8728,
            'site' => 'Ntinda Hub',
            'status' => 'online',
            'hardware_model' => 'CHR (Virtual)',
        ]);

        // Physical (simulated) Routers
        Router::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'NTD-CORE-01'], [
            'management_ip' => '10.20.1.1',
            'api_username' => 'hamnet-api',
            'api_password' => 'secure_password_1',
            'api_port' => 8728,
            'site' => 'Ntinda Hub',
            'status' => 'online',
            'hardware_model' => 'CCR2004',
        ]);
        Router::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'WDG-CORE-01'], [
            'management_ip' => '10.20.2.1',
            'api_username' => 'hamnet-api',
            'api_password' => 'secure_password_2',
            'api_port' => 8728,
            'site' => 'Wandegeya',
            'status' => 'online',
            'hardware_model' => 'RB4011',
        ]);

        // ─── 8. PACKAGES ────────────────────────────────────────
        $daily = Package::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'Daily Pass'], [
            'price' => 2000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 5,
            'device_limit' => 1,
            'data_limit' => 'Unlimited',
            'radius_profile_name' => 'KL-DAILY-5M',
            'is_published' => true,
            'is_popular' => true,
            'features' => ['Hotspot', 'Unlimited data'],
        ]);

        $weekly = Package::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'Weekly Pro'], [
            'price' => 8000,
            'currency' => 'UGX',
            'duration_value' => 7,
            'duration_unit' => 'days',
            'speed_limit_mbps' => 10,
            'device_limit' => 2,
            'data_limit' => 'Unlimited',
            'radius_profile_name' => 'KL-WEEKLY-10M',
            'is_published' => true,
            'features' => ['Hotspot', 'PPPoE'],
        ]);

        $monthly = Package::firstOrCreate(['tenant_id' => $acme->id, 'name' => 'Monthly Unlimited'], [
            'price' => 30000,
            'currency' => 'UGX',
            'duration_value' => 1,
            'duration_unit' => 'months',
            'speed_limit_mbps' => 20,
            'device_limit' => 4,
            'data_limit' => 'Unlimited',
            'radius_profile_name' => 'KL-MONTHLY-20M',
            'is_published' => true,
            'features' => ['Hotspot', 'PPPoE', 'Print'],
        ]);

        // ─── 9. TRANSACTIONS & VOUCHERS (Completed Sales) ───────
        // Create a batch
        $batch = VoucherBatch::firstOrCreate(['tenant_id' => $acme->id, 'batch_code' => 'BATCH-001'], [
            'package_id' => $daily->id,
            'quantity' => 50,
            'status' => 'active',
            'created_by' => $acmeAdmin->id,
        ]);

        // Create a completed sale
        for ($i = 0; $i < 5; $i++) {
            $txRef = 'TXN-' . strtoupper(\Illuminate\Support\Str::random(6));
            $voucherCode = 'KL-D7XP-' . strtoupper(\Illuminate\Support\Str::random(4));

            // Create the transaction (completed)
            $tx = Transaction::create([
                'tenant_id' => $acme->id,
                'type' => 'voucher_sale',
                'amount' => $daily->price,
                'platform_fee' => $daily->price * 0.05,
                'net_amount' => $daily->price * 0.95,
                'status' => 'completed',
                'reference' => $txRef,
                'description' => "Online Purchase: {$daily->name}",
                'meta' => ['package_id' => $daily->id, 'phone' => '25677200000' . $i],
            ]);

            // Credit the wallet
            $acme->increment('wallet_balance', $daily->price * 0.95);

            // Create the voucher
            Voucher::create([
                'tenant_id' => $acme->id,
                'voucher_batch_id' => $batch->id,
                'package_id' => $daily->id,
                'code' => $voucherCode,
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'redeemed_by' => '25677200000' . $i,
            ]);
        }

        // ─── 10. CUSTOMERS & SESSIONS ───────────────────────────
        $customer = Customer::firstOrCreate(['tenant_id' => $acme->id, 'phone' => '256772411883'], [
            'name' => 'John Doe',
            'status' => 'active',
        ]);

        // Create a session for the customer
        Session::firstOrCreate(['tenant_id' => $acme->id, 'customer_id' => $customer->id], [
            'voucher_id' => Voucher::withoutTenancy()->where('tenant_id', $acme->id)->firstOrFail()->id,
            'package_id' => $daily->id,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addHours(24),
            'ip_address' => '10.50.1.100',
            'bytes_in' => 1024 * 1024 * 50,
            'bytes_out' => 1024 * 1024 * 100,
        ]);

        $this->call(PolicySeeder::class);

        $this->command->info('Database seeded successfully.');
        $this->command->info('Seeded accounts use SEED_ADMIN_PASSWORD when set; otherwise a random password is generated.');
    }
}