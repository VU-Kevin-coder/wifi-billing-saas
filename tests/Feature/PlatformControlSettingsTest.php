<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformControlSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'security.platform_admin_mfa_required' => false,
            'security.step_up_enabled' => false,
        ]);
        Policy::set('security.platform_admin_mfa', false);
        Policy::set('security.step_up_enabled', false);

        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_network', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');
    }

    public function test_control_settings_page_renders_real_health_and_section_reset_controls(): void
    {
        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'vouchers']))
            ->assertOk()
            ->assertSee('Reset section')
            ->assertSee('Highest allowed speed')
            ->assertSee('Allowed characters');

        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control', ['section' => 'health']))
            ->assertOk()
            ->assertSee('Current status')
            ->assertSee('Site agents');
    }

    public function test_settings_are_saved_and_audited_with_the_admin_reason(): void
    {
        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'section' => 'tenants',
                'reason' => 'Adjust workspace capacity',
                'settings' => ['tenant.max_sites' => 7],
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'tenants']))
            ->assertSessionHas('status', '1 setting(s) saved.');

        $this->assertSame(7, (int) PlatformSetting::get('tenant.max_sites'));
        $this->assertSame(
            'Adjust workspace capacity',
            AuditLog::where('action', 'platform.settings.updated')->sole()->meta['reason'],
        );
    }

    public function test_admin_can_resume_payouts_after_freezing_them(): void
    {
        PlatformSetting::set('payouts.paused', true);

        $this->actingAs($this->admin)
            ->post(route('platform.configuration.control.action', ['action' => 'resume']), [
                'confirmation' => 'RESUME',
                'reason' => 'Payout provider recovered',
            ])
            ->assertRedirect(route('platform.configuration.control', ['section' => 'danger']))
            ->assertSessionHas('status', 'Resume completed.');

        $this->assertFalse(PlatformSetting::get('payouts.paused'));
        $this->assertSame('resume', AuditLog::where('action', 'platform.settings.action')->sole()->meta['action']);
    }

    public function test_admin_ip_allow_list_is_enforced_and_validated(): void
    {
        PlatformSetting::set('security.admin_ip_allow', ['10.0.0.0/8']);

        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control'))
            ->assertForbidden();

        PlatformSetting::set('security.admin_ip_allow', ['127.0.0.1/32']);
        $this->actingAs($this->admin)
            ->get(route('platform.configuration.control'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->put(route('platform.configuration.control.update'), [
                'reason' => 'Reject invalid network range',
                'settings' => ['security.admin_ip_allow' => ['not-a-network']],
            ])
            ->assertSessionHasErrors('value.0');
    }

    public function test_public_api_rate_limit_uses_the_platform_setting(): void
    {
        PlatformSetting::set('security.api_rate', 10);

        for ($request = 0; $request < 10; $request++) {
            $this->getJson('/api/payments/status')->assertStatus(404);
        }

        $this->getJson('/api/payments/status')->assertTooManyRequests();
    }
}
