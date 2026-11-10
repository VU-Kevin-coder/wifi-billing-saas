<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PolicySetting;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountPasswordSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_demo_session_with_a_tenant_voucher(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tenantId = Tenant::where('slug', 'acme')->value('id');
        $customerId = Customer::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('phone', '256772411883')
            ->value('id');
        $session = Session::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->firstOrFail();

        $this->assertDatabaseHas('vouchers', [
            'id' => $session->voucher_id,
            'tenant_id' => $tenantId,
        ]);
        $this->assertTrue(PolicySetting::where('key', 'security.platform_admin_mfa')->exists());
    }

    public function test_it_seeds_separate_passwords_and_updates_existing_seeded_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $platformAdmin = User::withoutTenancy()->where('email', 'platform@hamnet.test')->firstOrFail();
        $acmeAdmin = User::withoutTenancy()->where('email', 'admin@acme.test')->firstOrFail();
        $financeUser = User::withoutTenancy()->where('email', 'finance@acme.test')->firstOrFail();
        $betaAdmin = User::withoutTenancy()->where('email', 'admin@beta.test')->firstOrFail();

        $this->assertTrue(Hash::check('Platform-test-password-2026!', $platformAdmin->password));
        foreach ([$acmeAdmin, $financeUser, $betaAdmin] as $operator) {
            $this->assertTrue(Hash::check('Operator-test-password-2026!', $operator->password));
        }

        $platformAdmin->forceFill(['password' => Hash::make('old-platform-password')])->save();
        $acmeAdmin->forceFill(['password' => Hash::make('old-operator-password')])->save();

        $this->seed(DemoAccountPasswordSeeder::class);

        $this->assertTrue(Hash::check(
            'Platform-test-password-2026!',
            $platformAdmin->fresh()->password,
        ));
        $this->assertTrue(Hash::check(
            'Operator-test-password-2026!',
            $acmeAdmin->fresh()->password,
        ));
    }
}
