<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\PolicySetting;
use App\Models\User;
use App\Services\Policy\Policy;
use CodingLibs\MFA\Facades\MFA;
use Database\Seeders\PolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PolicyControlSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PolicySeeder::class);
        config(['security.platform_admin_mfa_required' => false, 'security.step_up_enabled' => false]);
        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_network', 'operator_support'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->admin = User::factory()->create(['tenant_id' => null]);
        $this->admin->assignRole('platform_admin');
        $this->actingAs($this->admin);
    }

    private function stored(string $key): mixed
    {
        return PolicySetting::where('key', $key)->firstOrFail()->value;
    }

    public function test_controls_are_typed_and_full_section_submission_preserves_unchanged_values(): void
    {
        $page = $this->get(route('platform.configuration.control', ['section' => 'wallet']))->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($page);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@type="number" and @name="policies[wallet.min_withdrawal]"]')->length);
        $this->assertSame(1, $xpath->query('//input[@type="checkbox" and @name="policies[approval.wallet_adjustment.enabled]"]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="policies[approval.wallet_adjustment.mode]"]')->length);
        $this->put(route('platform.configuration.control.update'), [
            'section' => 'wallet', 'reason' => 'Increase withdrawal minimum',
            'policies' => [
                'wallet.min_withdrawal' => '15000', 'wallet.withdrawal_pending_limit' => '1',
                'wallet.withdrawal_manual_review_hours' => '24', 'wallet.max_adjustment' => '1000000',
                'approval.wallet_adjustment.enabled' => '1', 'approval.wallet_adjustment.mode' => 'always',
                'approval.wallet_adjustment.threshold' => '0', 'approval.wallet_adjustment.min_admins' => '2',
                'approval.wallet_adjustment.self_approve' => '0', 'approval.wallet_adjustment.expiry_hours' => '24',
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(15000, $this->stored('wallet.min_withdrawal'));
        $this->assertSame(true, $this->stored('approval.wallet_adjustment.enabled'));
        $this->assertSame(false, $this->stored('approval.wallet_adjustment.self_approve'));
        $this->assertSame(1000000, $this->stored('wallet.max_adjustment'));
        $audit = AuditLog::where('action', 'platform.settings.updated')->sole();
        $this->assertSame('Increase withdrawal minimum', $audit->meta['reason']);
        $this->assertCount(1, $audit->meta['changes']);
    }

    public function test_control_rejects_invalid_values_and_is_atomic(): void
    {
        foreach (['2', 'true', 'not-bool', []] as $bad) {
            $this->putJson(route('platform.configuration.control.update'), [
                'reason' => 'Change settings safely',
                'policies' => ['wallet.min_withdrawal' => '20000', 'approval.wallet_adjustment.enabled' => $bad],
            ])->assertUnprocessable();
            $this->assertSame(10000, $this->stored('wallet.min_withdrawal'));
            $this->assertTrue($this->stored('approval.wallet_adjustment.enabled'));
        }
        foreach (['-1', '10000001', '2.5', '1e3'] as $bad) {
            $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Invalid number', 'policies' => ['wallet.min_withdrawal' => $bad]])->assertUnprocessable();
        }
        $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Invalid option', 'policies' => ['approval.wallet_adjustment.mode' => 'bypass']])->assertUnprocessable();
        $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Invalid key', 'policies' => ['approval.malicious' => 1]])->assertUnprocessable();
    }

    public function test_policy_center_uses_schema_not_corrupted_values_and_never_silently_submits_them(): void
    {
        Policy::set('approval.wallet_adjustment.enabled', 1);
        Policy::set('wallet.min_withdrawal', 'damaged');
        $page = $this->get(route('platform.policies.index'))->assertOk()->assertSee('Invalid or unknown policy value')->getContent();
        $dom = new \DOMDocument(); @$dom->loadHTML($page); $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@name="policies[approval.wallet_adjustment.enabled]"]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="policies[wallet.min_withdrawal]"]')->length);
        $this->put(route('platform.policies.update'), ['reason' => 'Adjust expiry window', 'policies' => ['approval.wallet_adjustment.expiry_hours' => '25']])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->stored('approval.wallet_adjustment.enabled'));
        $this->assertSame('damaged', $this->stored('wallet.min_withdrawal'));
        $this->putJson(route('platform.policies.update'), ['reason' => 'Reject invalid bool', 'policies' => ['approval.wallet_adjustment.enabled' => '2']])->assertUnprocessable();
        $this->put(route('platform.policies.update'), ['reason' => 'Explicitly restore boolean', 'policies' => ['approval.wallet_adjustment.enabled' => '0']])->assertSessionHasNoErrors();
        $this->assertSame(false, $this->stored('approval.wallet_adjustment.enabled'));
    }

    public function test_legacy_writes_cannot_inject_keys_or_skip_validation_or_reason(): void
    {
        $cases = [
            ['platform.policies.lifecycle.update', [], ['lifecycle.suspended.portal_sell' => 1, 'security.step_up_enabled' => 0]],
            ['platform.policies.lifecycle.update', [], ['lifecycle.fake.portal_sell' => 1]],
            ['platform.policies.category.update', ['category' => 'security'], ['security.nonexistent' => 'yes']],
            ['platform.policies.category.update', ['category' => 'security'], ['security.step_up_minutes' => -500]],
            ['platform.policies.category.update', ['category' => 'security'], ['security.step_up_enabled' => 'false']],
            ['platform.policies.category.update', ['category' => 'wallet'], ['security.step_up_enabled' => 0]],
        ];
        foreach ($cases as [$route, $parameters, $policies]) {
            $this->putJson(route($route, $parameters), ['reason' => 'Attempt unsafe write', 'policies' => $policies])->assertUnprocessable();
        }
        $this->putJson(route('platform.policies.lifecycle.update'), ['policies' => ['lifecycle.suspended.portal_sell' => 1]])->assertUnprocessable();
        $this->putJson(route('platform.policies.approval.update'), ['reason' => 'Invalid approval', 'enabled' => 1, 'self_approve' => 0, 'mode' => 'never', 'threshold' => -1, 'expiry_hours' => 24, 'min_admins' => 2])->assertUnprocessable();
        $this->assertSame(false, $this->stored('lifecycle.suspended.portal_sell'));
        $this->assertTrue($this->stored('security.step_up_enabled'));
        $this->assertSame('always', $this->stored('approval.wallet_adjustment.mode'));
        $this->assertDatabaseMissing('policy_settings', ['key' => 'security.nonexistent']);
        $this->assertSame(0, AuditLog::where('action', 'like', 'policy.%')->count());
    }

    public function test_legacy_valid_changes_are_typed_and_audited_even_when_general_auditing_is_disabled(): void
    {
        PlatformSetting::set('audit.enabled', false);
        $this->put(route('platform.policies.lifecycle.update'), ['reason' => 'Restore portal access', 'policies' => ['lifecycle.suspended.portal_sell' => '1']])->assertSessionHasNoErrors();
        $this->assertSame(true, $this->stored('lifecycle.suspended.portal_sell'));
        $audit = AuditLog::where('action', 'policy.lifecycle.updated')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('Restore portal access', $audit->meta['reason']);
        $this->assertSame(false, $audit->meta['changes']['lifecycle.suspended.portal_sell']['old']);
        $this->assertSame(true, $audit->meta['changes']['lifecycle.suspended.portal_sell']['new']);
        $this->put(route('platform.policies.category.update', ['category' => 'security']), ['reason' => 'Increase step up window', 'policies' => ['security.step_up_minutes' => '15']])->assertSessionHasNoErrors();
        $this->assertSame(15, $this->stored('security.step_up_minutes'));
        $this->put(route('platform.policies.approval.update'), ['reason' => 'Adjust review settings', 'enabled' => '1', 'self_approve' => '0', 'mode' => 'threshold', 'threshold' => '5000', 'expiry_hours' => '25', 'min_admins' => '2'])->assertSessionHasNoErrors();
        $this->assertSame('threshold', $this->stored('approval.wallet_adjustment.mode'));
        $this->assertSame(5000, $this->stored('approval.wallet_adjustment.threshold'));
        $this->assertSame(false, $this->stored('approval.wallet_adjustment.self_approve'));
        $this->assertSame(1, AuditLog::where('action', 'policy.approval.updated')->count());
    }

    public function test_each_legacy_route_requires_recent_mfa_when_enabled(): void
    {
        config(['security.step_up_enabled' => true]);
        MFA::enableMethod($this->admin, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);
        $stale = ['mfa' => ['verified' => true, 'verified_at' => now()->subMinutes(30)->getTimestamp()]];
        $requests = [
            [route('platform.policies.lifecycle.update'), ['reason' => 'Change lifecycle', 'policies' => ['lifecycle.suspended.portal_sell' => 1]]],
            [route('platform.policies.category.update', ['category' => 'security']), ['reason' => 'Change security', 'policies' => ['security.step_up_enabled' => 0]]],
            [route('platform.policies.approval.update'), ['reason' => 'Change approval', 'enabled' => 1, 'self_approve' => 0, 'mode' => 'never', 'threshold' => 0, 'expiry_hours' => 24, 'min_admins' => 2]],
        ];
        foreach ($requests as [$url, $body]) {
            $this->withSession($stale)->put($url, $body)->assertRedirect(route('mfa.step-up'));
        }
        $this->assertTrue($this->stored('security.step_up_enabled'));
        $this->withSession(['mfa' => ['verified' => true, 'verified_at' => now()->getTimestamp()]])->put($requests[0][0], $requests[0][1])->assertSessionHasNoErrors();
        $this->assertTrue($this->stored('lifecycle.suspended.portal_sell'));
    }

    public function test_complete_policy_center_submission_keeps_every_seeded_type(): void
    {
        $policies = [];
        foreach (PolicySetting::all() as $setting) {
            $policies[$setting->key] = is_bool($setting->value) ? ($setting->value ? '1' : '0') : (string) $setting->value;
        }
        $this->put(route('platform.policies.update'), ['reason' => 'Review existing policies', 'policies' => $policies])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'No policies changed.');
        $this->assertSame(true, $this->stored('security.platform_admin_mfa'));
        $this->assertSame(false, $this->stored('lifecycle.suspended.portal_sell'));
        $this->assertSame(10000, $this->stored('wallet.min_withdrawal'));
        $this->assertSame('always', $this->stored('approval.wallet_adjustment.mode'));
        $this->assertSame(0, AuditLog::where('action', 'like', 'policy.%')->count());
    }

    public function test_legacy_routes_enforce_platform_role_and_ip_allow_list(): void
    {
        PlatformSetting::set('security.admin_ip_allow', ['10.0.0.0/8']);
        $this->put(route('platform.policies.lifecycle.update'), ['reason' => 'Attempt network bypass', 'policies' => ['lifecycle.suspended.portal_sell' => 1]])->assertForbidden();
        $this->put(route('platform.policies.approval.update'), ['reason' => 'Attempt network bypass'])->assertForbidden();
        $this->put(route('platform.policies.category.update', ['category' => 'security']), ['reason' => 'Attempt network bypass', 'policies' => ['security.step_up_enabled' => 0]])->assertForbidden();
        PlatformSetting::set('security.admin_ip_allow', []);
        $operator = User::factory()->create();
        $operator->assignRole('operator_admin');
        $this->actingAs($operator)->put(route('platform.policies.lifecycle.update'), ['reason' => 'Attempt role bypass', 'policies' => ['lifecycle.suspended.portal_sell' => 1]])->assertForbidden();
        $this->assertFalse($this->stored('lifecycle.suspended.portal_sell'));
        $this->assertTrue($this->stored('security.step_up_enabled'));
    }

    public function test_control_does_not_render_damaged_boolean_as_a_guess(): void
    {
        Policy::set('approval.wallet_adjustment.enabled', 'damaged');
        $page = $this->get(route('platform.configuration.control', ['section' => 'wallet']))->assertOk()->assertSee('Invalid stored policy value')->getContent();
        $dom = new \DOMDocument(); @$dom->loadHTML($page); $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@name="policies[approval.wallet_adjustment.enabled]"]')->length);
        $this->put(route('platform.configuration.control.update'), ['reason' => 'Change wallet limit', 'policies' => ['wallet.min_withdrawal' => 12000]])->assertSessionHasNoErrors();
        $this->assertSame('damaged', $this->stored('approval.wallet_adjustment.enabled'));
    }

    public function test_policy_and_control_writes_roll_back_when_audit_fails(): void
    {
        Schema::drop('audit_logs');
        $this->putJson(route('platform.policies.update'), ['reason' => 'Change payout limit', 'policies' => ['wallet.min_withdrawal' => 20000]])->assertStatus(500);
        $this->assertSame(10000, $this->stored('wallet.min_withdrawal'));
        $this->putJson(route('platform.configuration.control.update'), ['reason' => 'Change payout limit', 'policies' => ['wallet.min_withdrawal' => 20000]])->assertStatus(500);
        $this->assertSame(10000, $this->stored('wallet.min_withdrawal'));
    }
}
