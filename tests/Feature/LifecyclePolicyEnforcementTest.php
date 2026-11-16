<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireRecentMfa;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\PolicySetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use Database\Seeders\PolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LifecyclePolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['operator_admin', 'operator_finance', 'operator_support', 'platform_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'suspended', 'kyc_status' => 'verified', 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('operator_admin');
        URL::defaults(['tenant' => 'acme']);
    }

    public function test_catalog_defaults_apply_without_seeding_and_optional_billing_is_unconstrained(): void
    {
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));
        $this->assertTrue(Policy::denies($this->tenant, 'dashboard'));
        $this->assertFalse(Policy::denies($this->tenant, 'view_billing'));
        $this->tenant->update(['status' => 'active', 'kyc_status' => 'pending']);
        $this->assertTrue(Policy::denies($this->tenant, 'withdraw'));
        $this->assertFalse(Policy::denies($this->tenant, 'view_billing'));
        Policy::set('lifecycle.kyc_pending.view_billing', false);
        $this->assertTrue(Policy::denies($this->tenant, 'view_billing'));
    }

    public function test_combined_states_and_explicit_changes_are_enforced_without_stale_reads(): void
    {
        $this->tenant->update(['kyc_status' => 'pending']);
        Policy::set('lifecycle.suspended.dashboard', true);
        $this->assertFalse(Policy::denies($this->tenant, 'dashboard'));
        Policy::set('lifecycle.kyc_pending.dashboard', false);
        $this->assertTrue(Policy::denies($this->tenant, 'dashboard'));
        PolicySetting::where('key', 'lifecycle.kyc_pending.dashboard')->update(['value' => 'true']);
        $this->assertFalse(Policy::denies($this->tenant, 'dashboard'));
        Policy::set('lifecycle.kyc_pending.dashboard', 'false');
        $this->assertTrue(Policy::denies($this->tenant, 'dashboard'));
    }

    public function test_policy_storage_failure_denies_applicable_state_even_after_cached_allow(): void
    {
        Policy::set('lifecycle.suspended.portal_sell', true);
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
        Schema::drop('policy_settings');
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));
        $this->assertTrue(Policy::denies($this->tenant, 'view_billing'));
        $this->tenant->status = 'active';
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
    }

    public function test_dashboard_reports_and_billing_are_gated_and_no_redirect_loop(): void
    {
        $this->actingAs($this->admin)->get(route('operator.dashboard'))->assertRedirect(route('operator.billing.index'));
        $this->actingAs($this->admin)->get(route('operator.billing.index'))->assertOk();
        Policy::set('lifecycle.suspended.view_reports', false);
        foreach (['operator.analytics', 'operator.sites.index', 'operator.sites.export'] as $name) {
            $this->actingAs($this->admin)->getJson(route($name))->assertForbidden();
        }
        Policy::set('lifecycle.suspended.view_billing', false);
        $this->actingAs($this->admin)->get(route('operator.billing.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('operator.dashboard'))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('operator.dashboard'))->assertForbidden();
        Policy::set('lifecycle.suspended.dashboard', true);
        $this->actingAs($this->admin)->get(route('operator.dashboard'))->assertOk();
    }

    public function test_support_is_not_redirected_to_role_restricted_billing(): void
    {
        $support = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $support->assignRole('operator_support');
        $this->actingAs($support)->get(route('operator.dashboard'))->assertForbidden();
    }

    public function test_seeder_preserves_existing_policy_and_fills_missing_keys(): void
    {
        Policy::set('lifecycle.suspended.dashboard', true, 'local choice');
        Policy::set('security.step_up_minutes', 31, 'local security');
        $this->seed(PolicySeeder::class);
        $this->seed(PolicySeeder::class);
        $row = PolicySetting::where('key', 'lifecycle.suspended.dashboard')->sole();
        $this->assertTrue($row->value);
        $this->assertSame('local choice', $row->description);
        $this->assertSame(31, Policy::get('security.step_up_minutes'));
        $this->assertDatabaseHas('policy_settings', ['key' => 'wallet.min_withdrawal']);
    }

    public function test_security_and_financial_changes_are_audited_with_optional_audit_disabled(): void
    {
        $this->seed(PolicySeeder::class);
        PlatformSetting::set('audit.enabled', false);
        $platform = User::factory()->create(['tenant_id' => null]);
        $platform->assignRole('platform_admin');
        $this->withoutMiddleware(RequireRecentMfa::class);
        $this->actingAs($platform)->put(route('platform.policies.update'), [
            'reason' => 'Review policy',
            'policies' => ['security.step_up_enabled' => '0', 'wallet.min_withdrawal' => '20000'],
        ])->assertSessionHasNoErrors();
        $this->assertFalse(Policy::get('security.step_up_enabled'));
        $this->assertSame(20000, Policy::get('wallet.min_withdrawal'));
        foreach (['security', 'wallet'] as $category) {
            $log = AuditLog::where('action', "policy.{$category}.updated")->sole();
            $this->assertSame($platform->id, $log->user_id);
            $this->assertSame('Review policy', $log->meta['reason']);
            $this->assertNotEmpty($log->meta['changes']);
        }
    }

    public function test_subscription_states_require_the_existing_expiry_conditions(): void
    {
        $this->tenant->update(['status' => 'active', 'subscription_status' => 'past_due', 'subscription_ends_at' => null]);
        $this->assertFalse(Policy::denies($this->tenant, 'withdraw'));
        $this->tenant->update(['subscription_ends_at' => now()->subMinute()]);
        $this->assertTrue(Policy::denies($this->tenant, 'withdraw'));
        $this->tenant->update(['subscription_status' => 'trial', 'trial_ends_at' => now()->addMinute()]);
        $this->assertFalse(Policy::denies($this->tenant, 'portal_sell'));
        $this->tenant->update(['trial_ends_at' => now()->subMinute()]);
        $this->assertTrue(Policy::denies($this->tenant, 'portal_sell'));
        $this->assertFalse(Policy::denies($this->tenant, 'portal_redeem'));
    }

    public function test_legacy_policy_updates_cannot_smuggle_other_categories_and_are_audited(): void
    {
        $this->seed(PolicySeeder::class);
        PlatformSetting::set('audit.enabled', false);
        $platform = User::factory()->create(['tenant_id' => null]);
        $platform->assignRole('platform_admin');
        $this->withoutMiddleware(RequireRecentMfa::class);
        $this->actingAs($platform)->put(route('platform.policies.lifecycle.update'), [
            'policies' => ['security.step_up_enabled' => '0'],
        ])->assertSessionHasErrors('policies');
        $this->assertTrue(Policy::get('security.step_up_enabled'));
        $this->actingAs($platform)->put(route('platform.policies.category.update', 'security'), [
            'policies' => ['security.step_up_enabled' => '0'],
        ])->assertSessionHasNoErrors();
        $log = AuditLog::where('action', 'policy.security.updated')->sole();
        $this->assertSame(['old' => true, 'new' => false, 'description' => 'Require recent MFA for sensitive actions'], $log->meta['changes']['security.step_up_enabled']);
    }

    public function test_policy_change_rolls_back_if_mandatory_audit_fails(): void
    {
        $this->seed(PolicySeeder::class);
        PlatformSetting::set('audit.enabled', false);
        $platform = User::factory()->create(['tenant_id' => null]);
        $platform->assignRole('platform_admin');
        $this->withoutMiddleware(RequireRecentMfa::class);
        Schema::drop('audit_logs');
        $this->actingAs($platform)->put(route('platform.policies.update'), [
            'reason' => 'Review policy', 'policies' => ['security.step_up_enabled' => '0'],
        ])->assertStatus(500);
        Policy::flush();
        $this->assertTrue(Policy::get('security.step_up_enabled'));
    }
}
