<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SafeControlUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.platform_admin_mfa_required' => false, 'security.step_up_enabled' => false]);
        Policy::set('security.platform_admin_mfa', false);
        Policy::set('security.step_up_enabled', false);
        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_network', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');
        $this->actingAs($this->admin);
    }

    public function test_danger_screen_tracks_freeze_reset_and_explicit_resume(): void
    {
        $screen = route('platform.configuration.control', ['section' => 'danger']);
        $this->get($screen)->assertOk()->assertSee('Suspend all payouts')->assertDontSee('Resume payouts');

        $this->post(route('platform.configuration.control.action', 'freeze'), ['confirmation' => 'FREEZE', 'reason' => 'Provider investigation'])->assertRedirect();
        $this->assertTrue(PlatformSetting::get('payouts.paused'));
        $this->get($screen)->assertOk()->assertSee('Resume payouts')->assertDontSee('Suspend all payouts');

        $this->post(route('platform.configuration.control.action', 'reset'), ['confirmation' => 'RESET', 'reason' => 'Reset noncritical settings'])->assertRedirect();
        $this->assertTrue(PlatformSetting::get('payouts.paused'));
        $this->get($screen)->assertOk()->assertSee('Resume payouts')->assertDontSee('Suspend all payouts');

        $this->post(route('platform.configuration.control.action', 'resume'), ['confirmation' => 'RESUME', 'reason' => 'Provider recovered'])->assertRedirect();
        $this->assertFalse(PlatformSetting::get('payouts.paused'));
        $this->get($screen)->assertOk()->assertSee('Suspend all payouts')->assertDontSee('Resume payouts');
        $this->assertSame(['freeze', 'reset', 'resume'], AuditLog::where('action', 'platform.settings.action')->orderBy('id')->get()->map(fn ($row) => $row->meta['action'])->all());
    }

    public function test_bulk_rotation_leaves_revoked_agents_unchanged_and_rotates_only_active_agents(): void
    {
        $tenant = Tenant::create(['name' => 'Test tenant', 'slug' => 'test-tenant', 'status' => 'active']);
        $activeSite = Site::create(['tenant_id' => $tenant->id, 'name' => 'Active site']);
        $revokedSite = Site::create(['tenant_id' => $tenant->id, 'name' => 'Revoked site']);
        $active = SiteAgent::create(['tenant_id' => $tenant->id, 'site_id' => $activeSite->id, 'name' => 'Active agent', 'token_hash' => hash('sha256', 'old-active'), 'last_seen_at' => now()]);
        $revoked = SiteAgent::create(['tenant_id' => $tenant->id, 'site_id' => $revokedSite->id, 'name' => 'Revoked agent', 'token_hash' => hash('sha256', 'old-revoked'), 'revoked_at' => now(), 'last_seen_at' => now()]);
        $oldRevokedTime = $revoked->revoked_at->toDateTimeString();
        $oldRevokedSeen = $revoked->last_seen_at->toDateTimeString();

        $response = $this->post(route('platform.configuration.control.action', 'rotate'), ['confirmation' => 'ROTATE', 'reason' => 'Rotate compromised tokens']);
        $response->assertRedirect()->assertSessionHas('control_action_result.rotated_agents', 1);
        $tokens = session('control_action_result.tokens');
        $this->assertCount(1, $tokens);
        $this->assertSame('Active site', $tokens[0]['site']);
        $this->assertSame(hash('sha256', $tokens[0]['token']), $active->fresh()->token_hash);
        $this->assertNull($active->fresh()->last_seen_at);
        $this->assertSame(hash('sha256', 'old-revoked'), $revoked->fresh()->token_hash);
        $this->assertSame($oldRevokedTime, $revoked->fresh()->revoked_at->toDateTimeString());
        $this->assertSame($oldRevokedSeen, $revoked->fresh()->last_seen_at->toDateTimeString());
        $this->assertArrayNotHasKey('tokens', AuditLog::where('action', 'platform.settings.action')->sole()->meta['details']);
        $this->withToken('old-revoked')->getJson(route('api.site-agent.commands'))->assertUnauthorized();
        $this->withToken('old-active')->getJson(route('api.site-agent.commands'))->assertUnauthorized();
        $this->withToken($tokens[0]['token'])->getJson(route('api.site-agent.commands'))->assertOk();
    }

    public function test_role_matrix_is_read_only_and_forged_submissions_are_rejected(): void
    {
        $response = $this->get(route('platform.configuration.control', ['section' => 'roles']))->assertOk()->assertSee('Permission matrix (not operative)')->assertSee('these assignments do not control application access');
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $boxes = $xpath->query('//table[contains(@class,"control-table")]//input[@type="checkbox"]');
        $this->assertGreaterThan(0, $boxes->length);
        foreach ($boxes as $box) {
            $this->assertTrue($box->hasAttribute('disabled'));
            $this->assertFalse($box->hasAttribute('name'));
            $this->assertFalse($box->hasAttribute('checked')); // No permissions were assigned.
        }
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Attempt permission changes', 'permission_roles' => ['operator_admin' => 1], 'permissions' => ['operator_admin' => ['something']]])->assertSessionHasErrors('permissions');
        $this->assertCount(0, Role::findByName('operator_admin')->permissions);
    }

    public function test_approval_setting_is_labelled_as_admin_count_not_required_approvals(): void
    {
        Policy::set('approval.wallet_adjustment.min_admins', 3, 'Minimum admins required for approval');
        foreach ([route('platform.configuration.control', ['section' => 'wallet']), route('platform.policies.index')] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('Minimum platform admins in the system')->assertSee('Only one approval is required per adjustment.')->assertDontSee('Approvals needed');
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $inputs = $xpath->query('//input[@name="policies[approval.wallet_adjustment.min_admins]"]');
            $this->assertSame(1, $inputs->length);
            $this->assertSame('number', $inputs->item(0)->getAttribute('type'));
            $this->assertSame('3', $inputs->item(0)->getAttribute('value'));
        }
    }
}
