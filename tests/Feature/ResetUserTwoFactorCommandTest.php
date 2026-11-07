<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResetUserTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function userWithMfa(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        MFA::enableMethod($user, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);

        return $user;
    }

    public function test_reset_disables_two_factor_and_is_audited(): void
    {
        $user = $this->userWithMfa();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        $this->artisan('users:reset-2fa', ['email' => $user->email, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $log = AuditLog::where('action', 'mfa.reset')->firstOrFail();
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame('cli', $log->meta['via']);
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $user = $this->userWithMfa();

        $this->artisan('users:reset-2fa', ['email' => $user->email])
            ->expectsConfirmation("Disable two-factor authentication for {$user->email}? Verify the person's identity first.", 'no')
            ->assertFailed();

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, AuditLog::where('action', 'mfa.reset')->count());
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('users:reset-2fa', ['email' => 'nobody@example.test', '--force' => true])
            ->assertFailed();
    }

    public function test_user_without_two_factor_is_a_no_op(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);

        $this->artisan('users:reset-2fa', ['email' => $user->email, '--force' => true])->assertSuccessful();

        $this->assertSame(0, AuditLog::where('action', 'mfa.reset')->count());
    }

    public function test_a_reset_platform_admin_is_sent_back_to_enrolment(): void
    {
        config(['security.platform_admin_mfa_required' => true]);
        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);

        $admin = $this->userWithMfa();
        $admin->assignRole('platform_admin');

        $this->artisan('users:reset-2fa', ['email' => $admin->email, '--force' => true])->assertSuccessful();

        $this->actingAs($admin->fresh())
            ->get(route('platform.dashboard'))
            ->assertRedirect(route('profile.security.index'));
    }
}
