<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use CodingLibs\MFA\Models\MfaChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MfaFactorManagementTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function recentMfaSession(): array
    {
        return [
            'mfa' => [
                'verified' => true,
                'verified_at' => now()->getTimestamp(),
            ],
        ];
    }

    private function latestChallenge(User $user, string $method): MfaChallenge
    {
        return MfaChallenge::query()
            ->where('model_type', User::class)
            ->where('model_id', $user->getKey())
            ->where('method', $method)
            ->latest('id')
            ->firstOrFail();
    }

    public function test_email_factor_requires_a_verified_contact_and_a_valid_code(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('profile.security.channels.challenge'), ['method' => 'email'])
            ->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('status');

        $challenge = $this->latestChallenge($user, 'email');
        $this->assertFalse(MFA::isEnabled($user, 'email'));

        $this->post(route('profile.security.channels.confirm'), [
            'method' => 'email',
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertFalse(MFA::isEnabled($user, 'email'));

        $this->post(route('profile.security.channels.confirm'), [
            'method' => 'email',
            'code' => $challenge->code,
        ])->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('recovery_codes');

        $this->assertTrue(MFA::isEnabled($user->fresh(), 'email'));
        $this->assertSame(10, MFA::getRemainingRecoveryCodesCount($user));
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_unverified_email_cannot_be_added_as_a_factor(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->post(route('profile.security.channels.challenge'), ['method' => 'email'])
            ->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('error');

        $this->assertFalse(MFA::isEnabled($user->fresh(), 'email'));
        $this->assertSame(0, MfaChallenge::query()->where('method', 'email')->count());
    }

    public function test_sms_enrollment_only_enables_after_code_and_verifies_phone(): void
    {
        $user = User::factory()->create(['phone' => '+256700000001']);
        $challenge = MFA::issueChallenge($user, 'sms', send: false);
        $this->assertNotNull($challenge);

        $this->actingAs($user)
            ->withSession(['mfa.enrollment' => ['method' => 'sms']])
            ->post(route('profile.security.channels.confirm'), [
                'method' => 'sms',
                'code' => $challenge->code,
            ])
            ->assertRedirect(route('profile.security.index'));

        $this->assertTrue(MFA::isEnabled($user->fresh(), 'sms'));
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_email_code_can_complete_the_login_challenge(): void
    {
        Mail::fake();
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('operator_admin');
        MFA::enableMethod($user, 'email');
        MFA::enableMethod($user, 'sms');
        $user->forceFill(['phone' => '+256700000003', 'phone_verified_at' => now()])->save();

        $this->post('http://acme.localhost/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('http://acme.localhost/two-factor-challenge');

        $this->get('http://acme.localhost/two-factor-challenge')
            ->assertOk()
            ->assertSee('Send Email code')
            ->assertSee('Send SMS code');

        $this->post('http://acme.localhost/two-factor-challenge/send', ['method' => 'email'])
            ->assertRedirect('http://acme.localhost/two-factor-challenge');
        $challenge = $this->latestChallenge($user, 'email');

        $this->postJson('http://acme.localhost/two-factor-challenge', [
            'method' => 'email',
            'code' => $challenge->code,
        ])
            ->assertOk()
            ->assertJsonStructure(['redirect'])
            ->assertJsonPath('redirect', 'http://acme.localhost/operator/dashboard');
    }

    public function test_removing_an_optional_last_factor_requires_password_and_clears_recovery_codes(): void
    {
        $user = User::factory()->create();
        MFA::enableMethod($user, 'email');
        MFA::generateRecoveryCodes($user);

        $this->actingAs($user)
            ->withSession($this->recentMfaSession())
            ->delete(route('profile.security.methods.remove', 'email'), ['password' => 'incorrect'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(MFA::isEnabled($user->fresh(), 'email'));

        $this->delete(route('profile.security.methods.remove', 'email'), ['password' => 'password'])
            ->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('status');

        $this->assertFalse(MFA::isEnabled($user->fresh(), 'email'));
        $this->assertSame(0, MFA::getRemainingRecoveryCodesCount($user));
        $this->assertNull($user->fresh()->mfa_enrollment_required_at);
        $this->assertSame(1, AuditLog::where('action', 'mfa.method_removed')->count());
    }

    public function test_policy_prevents_removing_the_last_factor(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'settings' => ['require_2fa' => true],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        MFA::enableMethod($user, 'totp', ['secret' => self::SECRET]);

        $this->actingAs($user)
            ->withSession($this->recentMfaSession())
            ->delete(route('profile.security.methods.remove', 'totp'), ['password' => 'password'])
            ->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('error');

        $this->assertTrue(MFA::isEnabled($user->fresh(), 'totp'));
    }

    public function test_platform_admin_can_reset_non_admin_factors_and_requires_reenrollment(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        MFA::enableMethod($admin, 'totp', ['secret' => self::SECRET]);

        $target = User::factory()->create(['phone' => '+256700000002']);
        MFA::enableMethod($target, 'totp', ['secret' => self::SECRET]);
        MFA::enableMethod($target, 'email');
        MFA::enableMethod($target, 'sms');
        MFA::generateRecoveryCodes($target);
        MFA::issueChallenge($target, 'email', send: false);

        $this->actingAs($admin)
            ->withSession($this->recentMfaSession())
            ->post(route('platform.mfa-recovery.reset', $target), [
                'reason' => 'User replaced their phone and lost all MFA access',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $target = $target->fresh();
        $this->assertSame([], $target->enabledTwoFactorMethods());
        $this->assertSame(0, MFA::getRemainingRecoveryCodesCount($target));
        $this->assertSame(0, MfaChallenge::query()
            ->where('model_type', User::class)
            ->where('model_id', $target->id)
            ->count());
        $this->assertNull($target->phone_verified_at);
        $this->assertNotNull($target->mfa_enrollment_required_at);

        $log = AuditLog::where('action', 'mfa.admin_reset')->firstOrFail();
        $this->assertSame($target->id, $log->auditable_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('User replaced their phone and lost all MFA access', $log->meta['reason']);
    }

    public function test_platform_admin_mfa_recovery_page_lists_non_admin_users(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        MFA::enableMethod($admin, 'totp', ['secret' => self::SECRET]);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->withSession($this->recentMfaSession())
            ->get(route('platform.mfa-recovery.index'))
            ->assertOk()
            ->assertSee('Reset all MFA')
            ->assertSee($user->email);
    }

    public function test_platform_admin_reset_is_denied_for_another_platform_admin(): void
    {
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        MFA::enableMethod($admin, 'totp', ['secret' => self::SECRET]);

        $target = User::factory()->create(['tenant_id' => null]);
        $target->assignRole('platform_admin');
        MFA::enableMethod($target, 'totp', ['secret' => self::SECRET]);

        $this->actingAs($admin)
            ->withSession($this->recentMfaSession())
            ->post(route('platform.mfa-recovery.reset', $target), [
                'reason' => 'Platform admin needs access recovery',
            ])
            ->assertForbidden();

        $this->assertTrue(MFA::isEnabled($target->fresh(), 'totp'));
        $this->assertSame(0, AuditLog::where('action', 'mfa.admin_reset')->count());
    }

    public function test_platform_admin_reset_requires_recent_mfa_even_if_step_up_middleware_is_disabled(): void
    {
        config(['security.step_up_enabled' => false]);
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('platform_admin');
        MFA::enableMethod($admin, 'totp', ['secret' => self::SECRET]);
        $target = User::factory()->create();
        MFA::enableMethod($target, 'totp', ['secret' => self::SECRET]);

        $this->actingAs($admin)
            ->withSession(['mfa' => ['verified' => true, 'verified_at' => now()->subMinutes(30)->getTimestamp()]])
            ->post(route('platform.mfa-recovery.reset', $target), [
                'reason' => 'User replaced their phone and lost all MFA access',
            ])
            ->assertRedirect(route('mfa.step-up'));

        $this->assertTrue(MFA::isEnabled($target->fresh(), 'totp'));
    }
}
