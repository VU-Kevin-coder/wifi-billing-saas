<?php

namespace Tests\Feature;

use App\Models\SsoNonce;
use App\Models\Tenant;
use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_requirement_redirects_unconfigured_users_to_security_setup(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['require_2fa' => true],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');

        $this->actingAs($user)
            ->get('http://acme.localhost/operator/settings')
            ->assertRedirect('http://acme.localhost/profile/security')
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertOk()
            ->assertSee('Enable 2FA');
    }

    public function test_two_factor_setup_stays_pending_until_a_code_is_confirmed(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['require_2fa' => true],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');

        $this->actingAs($user)
            ->post('http://acme.localhost/profile/security/enable')
            ->assertRedirect('http://acme.localhost/profile/security');

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertOk()
            ->assertSee('Scan this QR code')
            ->assertSee('Confirm');

        $this->actingAs($user)
            ->post('http://acme.localhost/profile/security/confirm', [
                'code' => '999999',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());

        $validCode = $this->currentTotpCode(MFA::getMethod($user, 'totp')->secret);

        $this->actingAs($user)
            ->post('http://acme.localhost/profile/security/confirm', [
                'code' => $validCode,
            ])
            ->assertRedirect('http://acme.localhost/profile/security')
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(10, MFA::getRemainingRecoveryCodesCount($user));

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertOk()
            ->assertSee('Save your recovery codes now');

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertOk()
            ->assertDontSee('Save your recovery codes now');
    }

    public function test_enabled_two_factor_users_can_access_tenant_workspace(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['require_2fa' => true],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');

        MFA::enableMethod($user, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertRedirect('http://acme.localhost/two-factor-challenge');

        $this->actingAs($user)
            ->withSession(['mfa' => ['verified' => true]])
            ->get('http://acme.localhost/profile/security')
            ->assertOk()
            ->assertSee('Two-Factor Authentication is enabled.');

        $this->actingAs($user)
            ->withSession(['mfa' => ['verified' => true]])
            ->get('http://acme.localhost/operator/settings')
            ->assertOk();
    }

    public function test_login_requires_and_verifies_totp_before_entering_workspace(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        MFA::enableMethod($user, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);

        $this->post('http://acme.localhost/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect('http://acme.localhost/two-factor-challenge');

        $this->assertAuthenticatedAs($user);

        $this->get('http://acme.localhost/operator/dashboard')
            ->assertRedirect('http://acme.localhost/two-factor-challenge');

        $this->get('http://acme.localhost/two-factor-challenge')
            ->assertOk()
            ->assertSee('authenticator app');

        $this->post('http://acme.localhost/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $validCode = $this->currentTotpCode('JBSWY3DPEHPK3PXP');
        $this->post('http://acme.localhost/two-factor-challenge', ['code' => $validCode])
            ->assertRedirect('http://acme.localhost/operator/dashboard');

        $this->get('http://acme.localhost/operator/dashboard')->assertOk();
    }

    public function test_mfa_is_preserved_through_signed_tenant_sso_transfer(): void
    {
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'Acme Wi-Fi', 'slug' => 'acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('operator_admin');
        MFA::enableMethod($user, 'totp', ['secret' => 'JBSWY3DPEHPK3PXP']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/two-factor-challenge');

        $verification = $this->post('/two-factor-challenge', [
            'code' => $this->currentTotpCode('JBSWY3DPEHPK3PXP'),
        ]);
        $verification->assertRedirect();
        $ssoUrl = $verification->headers->get('Location');
        $this->assertTrue(URL::hasValidSignature(request()->create($ssoUrl)));
        parse_str((string) parse_url($ssoUrl, PHP_URL_QUERY), $query);
        $nonce = SsoNonce::query()->where('token_hash', hash('sha256', $query['token']))->firstOrFail();
        $this->assertTrue($nonce->mfa_verified);

        $this->get($ssoUrl)
            ->assertRedirect('http://acme.localhost:8000/operator/dashboard');

        $this->get('http://acme.localhost:8000/operator/dashboard')->assertOk();
    }

    public function test_enforcement_does_not_break_unauthenticated_login_or_unrequired_tenants(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'settings' => ['require_2fa' => true],
        ]);

        $this->get('http://acme.localhost/login')->assertOk();

        $tenant->update(['settings' => ['require_2fa' => false]]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->get('http://acme.localhost/profile/security')
            ->assertOk();
    }

    private function currentTotpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $binarySecret = '';

        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos($alphabet, $character);
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binarySecret .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        $counter = intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $binarySecret, true);
        $offset = ord($hash[19]) & 0xF;
        $binaryCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binaryCode % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
