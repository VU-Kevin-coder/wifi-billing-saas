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

    public function test_explicit_seed_passwords_are_separate_and_update_existing_seeded_accounts(): void
    {
        $originalAdminPassword = getenv('SEED_ADMIN_PASSWORD');
        $originalTenantPassword = getenv('SEED_TENANT_PASSWORD');

        try {
            putenv('SEED_ADMIN_PASSWORD=Platform-test-password-2026!');
            putenv('SEED_TENANT_PASSWORD=Operator-test-password-2026!');
            $this->seed(DatabaseSeeder::class);

            $platformAdmin = User::withoutTenancy()->where('email', 'platform@hamnet.test')->firstOrFail();
            $acmeAdmin = User::withoutTenancy()->where('email', 'admin@acme.test')->firstOrFail();
            $financeUser = User::withoutTenancy()->where('email', 'finance@acme.test')->firstOrFail();
            $betaAdmin = User::withoutTenancy()->where('email', 'admin@beta.test')->firstOrFail();

            $this->assertTrue(Hash::check('Platform-test-password-2026!', $platformAdmin->password));
            foreach ([$acmeAdmin, $financeUser, $betaAdmin] as $operator) {
                $this->assertTrue(Hash::check('Operator-test-password-2026!', $operator->password));
            }

            putenv('SEED_ADMIN_PASSWORD=Updated-platform-password-2026!');
            putenv('SEED_TENANT_PASSWORD=Updated-operator-password-2026!');
            $this->seed(DemoAccountPasswordSeeder::class);

            $this->assertTrue(Hash::check(
                'Updated-platform-password-2026!',
                User::withoutTenancy()->findOrFail($platformAdmin->id)->password,
            ));
            $this->assertTrue(Hash::check(
                'Updated-operator-password-2026!',
                User::withoutTenancy()->findOrFail($acmeAdmin->id)->password,
            ));
        } finally {
            putenv('SEED_ADMIN_PASSWORD'.($originalAdminPassword === false ? '' : '='.$originalAdminPassword));
            putenv('SEED_TENANT_PASSWORD'.($originalTenantPassword === false ? '' : '='.$originalTenantPassword));
        }
    }
}
