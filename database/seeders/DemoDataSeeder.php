<?php

namespace Database\Seeders;

use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Platform Plans ──────────────────────────────────
        $starterPlan = PlatformPlan::firstOrCreate(
            ['name' => 'Starter'],
            [
                'price' => 100000,
                'trial_days' => 14,
                'max_customers' => 500,
                'max_routers' => 3,
                'features' => ['Up to 500 customers', 'Up to 3 routers', 'Basic reports'],
            ]
        );

        $growthPlan = PlatformPlan::firstOrCreate(
            ['name' => 'Growth'],
            [
                'price' => 180000,
                'trial_days' => 14,
                'max_customers' => 2000,
                'max_routers' => 10,
                'features' => ['Up to 2,000 customers', 'Up to 10 routers', 'M-Pesa / MoMo Integration', 'Priority Support'],
            ]
        );

        $enterprisePlan = PlatformPlan::firstOrCreate(
            ['name' => 'Enterprise'],
            [
                'price' => 350000,
                'trial_days' => 30,
                'max_customers' => 10000,
                'max_routers' => 50,
                'features' => ['Unlimited customers', 'Up to 50 routers', 'Dedicated Account Manager', 'Custom domain'],
            ]
        );

        // ─── Tenants ────────────────────────────────────────
        $acme = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            [
                'name'                  => 'KampalaLink Internet',
                'status'                => 'active',
                'legal_name'            => 'KampalaLink Telecom Ltd',
                'address'               => 'Ntinda Complex Road, Level 3',
                'contact_name'          => 'Sarah Nakato',
                'contact_email'         => 'sarah@kampala.net',
                'contact_phone'         => '+256 772 411 883',
                'platform_plan_id'      => $growthPlan->id,
                'plan'                  => 'Growth',
                'plan_fee'              => 180000,
                'subscription_status'   => 'active',
                'subscription_ends_at'  => now()->addDays(30),
            ]
        );
        if (!$acme->platform_plan_id) {
            $acme->platform_plan_id = $growthPlan->id;
            $acme->save();
        }

        $beta = Tenant::firstOrCreate(
            ['slug' => 'beta'],
            [
                'name'               => 'Beta Networks',
                'status'             => 'active',
                'legal_name'         => 'Beta Net Ltd',
                'contact_name'       => 'Peter Okello',
                'platform_plan_id'   => $starterPlan->id,
                'plan'               => 'Starter',
                'plan_fee'           => 100000,
                'subscription_status' => 'trial',
                'trial_ends_at'      => now()->addDays(7),
            ]
        );
        if (!$beta->platform_plan_id) {
            $beta->platform_plan_id = $starterPlan->id;
            $beta->save();
        }

        $seedPassword = (string) env('SEED_ADMIN_PASSWORD', '');
        if ($seedPassword === '') {
            $seedPassword = \Illuminate\Support\Str::password(24);
        }

        // ─── Platform Admin (no tenant) ─────────────────────
        $platformAdmin = User::firstOrCreate(
            ['email' => 'platform@wifi-billing.test'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make($seedPassword),
                'tenant_id' => null,
                'email_verified_at' => now(),
            ]
        );
        $platformAdmin->syncRoles(['platform_admin']);

        // ─── Acme Tenant Users ──────────────────────────────
        $acmeAdmin = User::firstOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Acme Owner',
                'password' => Hash::make($seedPassword),
                'tenant_id' => $acme->id,
                'email_verified_at' => now(),
            ]
        );
        $acmeAdmin->syncRoles(['operator_admin']);

        $acmeFinance = User::firstOrCreate(
            ['email' => 'finance@acme.test'],
            [
                'name' => 'Acme Finance',
                'password' => Hash::make($seedPassword),
                'tenant_id' => $acme->id,
                'email_verified_at' => now(),
            ]
        );
        $acmeFinance->syncRoles(['operator_finance']);

        // ─── Beta Tenant Users ──────────────────────────────
        $betaAdmin = User::firstOrCreate(
            ['email' => 'admin@beta.test'],
            [
                'name' => 'Beta Owner',
                'password' => Hash::make($seedPassword),
                'tenant_id' => $beta->id,
                'email_verified_at' => now(),
            ]
        );
        $betaAdmin->syncRoles(['operator_admin']);
    }
}
