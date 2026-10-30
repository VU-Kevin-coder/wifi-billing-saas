<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Tenants ────────────────────────────────────────
        $acme = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme Wi-Fi', 'status' => 'active']
        );

        $beta = Tenant::firstOrCreate(
            ['slug' => 'beta'],
            ['name' => 'Beta Networks', 'status' => 'active']
        );

        // ─── Platform Admin (no tenant) ─────────────────────
        $platformAdmin = User::firstOrCreate(
            ['email' => 'platform@wifi-billing.test'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
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
                'password' => Hash::make('password'),
                'tenant_id' => $acme->id,
                'email_verified_at' => now(),
            ]
        );
        $acmeAdmin->syncRoles(['operator_admin']);

        $acmeFinance = User::firstOrCreate(
            ['email' => 'finance@acme.test'],
            [
                'name' => 'Acme Finance',
                'password' => Hash::make('password'),
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
                'password' => Hash::make('password'),
                'tenant_id' => $beta->id,
                'email_verified_at' => now(),
            ]
        );
        $betaAdmin->syncRoles(['operator_admin']);
    }
}
