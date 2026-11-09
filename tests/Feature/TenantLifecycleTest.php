<?php

namespace Tests\Feature;

use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use Database\Seeders\PolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PolicySeeder::class);

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_finance', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'kyc_status' => 'verified', 'wallet_balance' => 100000,
            'subscription_status' => 'active',
        ]);
    }

    public function test_active_verified_tenant_can_sell(): void
    {
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
    }

    public function test_suspended_tenant_cannot_sell(): void
    {
        $this->tenant->update(['status' => 'suspended']);
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));
    }

    public function test_suspended_tenant_can_view_billing_to_pay(): void
    {
        $this->tenant->update(['status' => 'suspended']);
        $this->assertFalse(Policy::denies($this->tenant, 'view_billing'));
    }

    public function test_kyc_pending_tenant_cannot_withdraw(): void
    {
        $this->tenant->update(['kyc_status' => 'pending']);
        $this->assertTrue(Policy::denies($this->tenant, 'withdraw'));
    }

    public function test_kyc_pending_tenant_can_access_dashboard(): void
    {
        $this->tenant->update(['kyc_status' => 'pending']);
        $this->assertFalse(Policy::denies($this->tenant, 'dashboard'));
    }

    public function test_kyc_rejected_tenant_cannot_generate_vouchers(): void
    {
        $this->tenant->update(['kyc_status' => 'rejected']);
        $this->assertTrue(Policy::denies($this->tenant, 'generate_vouchers'));
    }

    public function test_past_due_tenant_can_sell_but_cannot_withdraw(): void
    {
        $this->tenant->update([
            'subscription_status' => 'past_due',
            'subscription_ends_at' => now()->subDay(),
        ]);
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
        $this->assertTrue(Policy::denies($this->tenant, 'withdraw'));
    }

    public function test_trial_expired_tenant_cannot_sell(): void
    {
        $this->tenant->update([
            'subscription_status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));
    }

    public function test_policy_change_immediately_affects_behavior(): void
    {
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));

        $this->tenant->update(['status' => 'suspended']);
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));

        // Allow suspended tenants to sell (unusual but configurable)
        Policy::set('lifecycle.suspended.portal_sell', true);
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
    }

    public function test_suspended_tenant_is_blocked_at_withdrawal_endpoint(): void
    {
        $this->tenant->update(['status' => 'suspended']);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'password' => bcrypt('password')]);
        $user->assignRole('operator_finance');
        URL::defaults(['tenant' => 'acme']);

        $this->actingAs($user)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000, 'phone' => '0771234567', 'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(); // redirected to billing

        $this->assertSame(100000, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_platform_admin_can_suspend_and_reactivate_tenant(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->post(route('platform.tenants.suspend', $this->tenant->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('suspended', $this->tenant->fresh()->status);

        $this->actingAs($admin)
            ->post(route('platform.tenants.reactivate', $this->tenant->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $this->tenant->fresh()->status);
    }

    public function test_platform_admin_can_verify_kyc(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $this->tenant->update(['kyc_status' => 'pending']);

        $this->actingAs($admin)
            ->post(route('platform.tenants.verify-kyc', $this->tenant->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('verified', $this->tenant->fresh()->kyc_status);
    }

    public function test_platform_admin_can_update_tenant_details(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');

        $this->actingAs($admin)
            ->put(route('platform.tenants.update', $this->tenant), [
                'name' => 'Acme Networks',
                'legal_name' => 'Acme Networks Ltd',
                'contact_name' => 'Jane Owner',
                'contact_email' => 'jane@acme.test',
                'contact_phone' => '+256700000001',
                'address' => 'Kampala',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Acme Networks', $this->tenant->fresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'tenant.updated',
        ]);
    }

    public function test_platform_admin_can_change_tenant_plan(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $plan = PlatformPlan::create([
            'name' => 'Growth',
            'price' => 180000,
            'trial_days' => 14,
            'max_customers' => 2000,
            'max_routers' => 10,
        ]);

        $this->actingAs($admin)
            ->put(route('platform.tenants.change-plan', $this->tenant), [
                'platform_plan_id' => $plan->id,
                'subscription_status' => 'active',
                'subscription_ends_at' => now()->addMonth()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($plan->id, $this->tenant->fresh()->platform_plan_id);
        $this->assertSame('Growth', $this->tenant->fresh()->getRawOriginal('plan'));
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'tenant.plan_changed',
        ]);
    }

    public function test_platform_admin_can_view_tenant_action_controls(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        $this->tenant->update(['kyc_status' => 'pending']);

        $this->actingAs($admin)
            ->get(route('platform.tenants.show', $this->tenant))
            ->assertOk()
            ->assertSee('Change plan')
            ->assertSee('Suspend')
            ->assertSee('Verify KYC')
            ->assertSee('Reject KYC')
            ->assertSee('Monthly revenue');
    }
}
