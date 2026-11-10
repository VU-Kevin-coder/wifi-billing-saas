<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private Tenant $tenant;

    private User $platformAdmin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml disables these for the older suites; this suite tests them.
        config([
            'security.platform_admin_mfa_required' => true,
            'security.step_up_enabled' => true,
            'security.step_up_minutes' => 10,
        ]);

        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_finance', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'Acme Wi-Fi',
            'slug' => 'acme',
            'status' => 'active',
            'wallet_balance' => 40000,
        ]);

        $this->platformAdmin = User::factory()->create(['tenant_id' => null]);
        $this->platformAdmin->assignRole('platform_admin');

        $this->finance = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->finance->assignRole('operator_finance');

        URL::defaults(['tenant' => $this->tenant->slug]);
    }

    private function enableMfa(User $user): User
    {
        MFA::enableMethod($user, 'totp', ['secret' => self::SECRET]);

        return $user;
    }

    /** Session state of a user who verified their code $minutesAgo minutes ago. */
    private function verifiedSession(int $minutesAgo = 0): array
    {
        return ['mfa' => ['verified' => true, 'verified_at' => now()->subMinutes($minutesAgo)->getTimestamp()]];
    }

    private function withdrawal(): Transaction
    {
        return Transaction::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'withdrawal',
            'amount' => 10000,
            'platform_fee' => 0,
            'net_amount' => -10000,
            'status' => 'pending',
            'reference' => 'WTH-MFA-001',
            'phone' => '0771234567',
            'meta' => [],
        ]);
    }

    private function totp(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';
        foreach (str_split(self::SECRET) as $c) {
            $buffer = ($buffer << 5) | strpos($alphabet, $c);
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        $hash = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30)), $binary, true);
        $o = ord($hash[19]) & 0xF;
        $code = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($code % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    // ── Platform admin enrolment ────────────────────────────────────────────

    public function test_platform_admin_without_mfa_is_sent_to_enrolment(): void
    {
        $this->actingAs($this->platformAdmin)
            ->get(route('platform.dashboard'))
            ->assertRedirect(route('profile.security.index'))
            ->assertSessionHas('error');

        $this->actingAs($this->platformAdmin)
            ->get(route('profile.security.index'))
            ->assertOk();
    }

    public function test_platform_admin_without_mfa_cannot_reach_money_routes(): void
    {
        $tx = $this->withdrawal();

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.revenue.withdrawals.approve', $tx->id))
            ->assertRedirect(route('profile.security.index'));

        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);
    }

    public function test_platform_admin_with_mfa_must_still_pass_the_login_challenge(): void
    {
        $this->enableMfa($this->platformAdmin);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.dashboard'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_mfa_challenge_supports_authenticator_and_recovery_code_modes(): void
    {
        $this->enableMfa($this->platformAdmin);

        $this->actingAs($this->platformAdmin)
            ->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertSee('Authenticator code')
            ->assertSee('Use a recovery code');

        $this->get(route('two-factor.challenge', ['mode' => 'recovery']))
            ->assertOk()
            ->assertSee('Recovery code')
            ->assertSee('Use an authenticator code');
    }

    public function test_platform_admin_with_verified_mfa_can_use_the_platform(): void
    {
        $this->enableMfa($this->platformAdmin);

        $this->actingAs($this->platformAdmin)
            ->withSession($this->verifiedSession())
            ->get(route('platform.dashboard'))
            ->assertOk();
    }

    public function test_enforcement_can_be_switched_off_for_bootstrapping(): void
    {
        config(['security.platform_admin_mfa_required' => false]);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.dashboard'))
            ->assertOk();
    }

    // ── Step-up on platform money actions ───────────────────────────────────

    public function test_stale_verification_blocks_withdrawal_approval_until_step_up(): void
    {
        $this->enableMfa($this->platformAdmin);
        $tx = $this->withdrawal();

        $this->actingAs($this->platformAdmin)
            ->withSession($this->verifiedSession(minutesAgo: 30))
            ->post(route('platform.revenue.withdrawals.reject', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertRedirect(route('mfa.step-up'));

        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);
        $this->assertEquals(40000, (float) $this->tenant->fresh()->wallet_balance);
    }

    public function test_recent_verification_allows_withdrawal_rejection(): void
    {
        $this->enableMfa($this->platformAdmin);
        $tx = $this->withdrawal();

        $this->actingAs($this->platformAdmin)
            ->withSession($this->verifiedSession(minutesAgo: 2))
            ->post(route('platform.revenue.withdrawals.reject', $tx->id), ['_idempotency_key' => $this->idempotencyKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', Transaction::withoutTenancy()->find($tx->id)->status);
    }

    public function test_wallet_adjustment_requires_step_up(): void
    {
        $this->enableMfa($this->platformAdmin);

        $this->actingAs($this->platformAdmin)
            ->withSession($this->verifiedSession(minutesAgo: 30))
            ->post(route('platform.revenue.adjust', $this->tenant->id), [
                'amount' => 5000,
                'reason' => 'Router compensation credit',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('mfa.step-up'));

        $this->assertEquals(40000, (float) $this->tenant->fresh()->wallet_balance);
    }

    public function test_payment_credentials_and_impersonation_require_step_up(): void
    {
        $this->enableMfa($this->platformAdmin);
        $this->actingAs($this->platformAdmin)->withSession($this->verifiedSession(minutesAgo: 30));

        $this->put(route('platform.configuration.flutterwave.update'), ['flutterwave_environment' => 'production'])
            ->assertRedirect(route('mfa.step-up'));
        $this->post(route('platform.impersonate', $this->tenant->id))
            ->assertRedirect(route('mfa.step-up'));

        $this->assertSame(0, AuditLog::where('action', 'platform.config.updated')->count());
    }

    // ── The step-up flow itself ─────────────────────────────────────────────

    public function test_step_up_flow_returns_the_user_and_unlocks_the_action(): void
    {
        $this->enableMfa($this->platformAdmin);
        $tx = $this->withdrawal();
        $revenue = route('platform.revenue.index');

        $this->actingAs($this->platformAdmin)->withSession($this->verifiedSession(minutesAgo: 30));

        $this->from($revenue)
            ->post(route('platform.revenue.withdrawals.reject', $tx->id))
            ->assertRedirect(route('mfa.step-up'));

        $this->get(route('mfa.step-up'))->assertOk()->assertSee('Confirm');

        $this->post(route('mfa.step-up.verify'), ['code' => $this->totp()])
            ->assertRedirect($revenue)
            ->assertSessionHas('status');

        $this->assertSame(1, AuditLog::where('action', 'mfa.step_up.verified')->count());

        $this->post(route('platform.revenue.withdrawals.reject', $tx->id), [
            '_idempotency_key' => $this->idempotencyKey(),
        ])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', Transaction::withoutTenancy()->find($tx->id)->status);
    }

    public function test_wrong_step_up_code_is_rejected_and_audited(): void
    {
        $this->enableMfa($this->platformAdmin);
        $tx = $this->withdrawal();

        $this->actingAs($this->platformAdmin)->withSession($this->verifiedSession(minutesAgo: 30));

        $this->post(route('mfa.step-up.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, AuditLog::where('action', 'mfa.step_up.failed')->count());

        $this->post(route('platform.revenue.withdrawals.reject', $tx->id))
            ->assertRedirect(route('mfa.step-up'));
        $this->assertSame('pending', Transaction::withoutTenancy()->find($tx->id)->status);
    }

    public function test_step_up_never_redirects_to_another_host(): void
    {
        $this->enableMfa($this->platformAdmin);

        $this->actingAs($this->platformAdmin)
            ->withSession($this->verifiedSession(minutesAgo: 30) + ['mfa.step_up_return' => 'https://evil.example/phish'])
            ->post(route('mfa.step-up.verify'), ['code' => $this->totp()])
            ->assertRedirect(route('dashboard'));
    }

    // ── Operator withdrawals ────────────────────────────────────────────────

    public function test_operator_without_mfa_cannot_request_a_withdrawal(): void
    {
        $this->actingAs($this->finance)
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'phone' => '0771234567',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('profile.security.index'));

        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'withdrawal')->count());
        $this->assertEquals(40000, (float) $this->tenant->fresh()->wallet_balance);
    }

    public function test_operator_with_stale_mfa_must_step_up_before_withdrawing(): void
    {
        $this->enableMfa($this->finance);

        $this->actingAs($this->finance)
            ->withSession($this->verifiedSession(minutesAgo: 30))
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'phone' => '0771234567',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertRedirect(route('mfa.step-up'));

        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'withdrawal')->count());
    }

    public function test_operator_with_recent_mfa_can_request_a_withdrawal(): void
    {
        $this->enableMfa($this->finance);

        $this->actingAs($this->finance)
            ->withSession($this->verifiedSession(minutesAgo: 1))
            ->post(route('operator.wallet.withdraw'), [
                'amount' => 10000,
                'phone' => '0771234567',
                'password' => 'password',
                '_idempotency_key' => $this->idempotencyKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::withoutTenancy()->where('type', 'withdrawal')->count());
        $this->assertEquals(30000, (float) $this->tenant->fresh()->wallet_balance);
    }
}
