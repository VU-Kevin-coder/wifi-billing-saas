<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PolicySetting;
use App\Models\Session;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
