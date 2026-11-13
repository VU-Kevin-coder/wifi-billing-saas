<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperatorControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['platform_admin', 'operator_admin', 'operator_finance', 'operator_support', 'operator_network'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $this->admin = $this->staff('operator_admin', 'Alice Admin');
        $this->package = Package::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Daily', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        URL::defaults(['tenant' => 'acme']);
    }

    private function staff(string $role, string $name = 'Staff Member', ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    // ── Voiding vouchers ────────────────────────────────────────────────────

    private function voucher(string $status = 'unused', string $code = 'VOID-001', ?Tenant $tenant = null): Voucher
    {
        $tenant ??= $this->tenant;
        $package = $tenant->is($this->tenant) ? $this->package : Package::create([
            'tenant_id' => $tenant->id, 'name' => 'Daily', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'days', 'speed_limit_mbps' => 5, 'device_limit' => 1,
        ]);
        $batch = VoucherBatch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'batch_code' => 'B-VOID'],
            ['package_id' => $package->id, 'quantity' => 5]
        );

        return Voucher::create([
            'tenant_id' => $tenant->id, 'voucher_batch_id' => $batch->id,
            'package_id' => $package->id, 'code' => $code, 'status' => $status,
        ]);
    }

    public function test_an_unused_voucher_can_be_voided_and_can_no_longer_be_redeemed(): void
    {
        $voucher = $this->voucher();

        $this->actingAs($this->admin)->post(route('operator.vouchers.void', $voucher->id))->assertSessionHasNoErrors();

        $this->assertSame('voided', $voucher->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'voucher.voided')->count());

        $this->post('http://acme.localhost/portal/redeem', ['voucher_code' => 'VOID-001'])->assertSessionHasErrors();
        $this->assertSame(0, Session::withoutTenancy()->count());
    }

    public function test_voiding_a_redeemed_voucher_without_a_router_does_not_claim_disconnection(): void
    {
        $voucher = $this->voucher('unused', 'VOID-002');
        $session = app(TenantContext::class)->runAs($this->tenant, fn () => app(VoucherActivationService::class)
            ->activate($this->tenant, $voucher, Request::create('/'), '0771234567'));
        $this->assertSame('active', $session->status);

        $this->actingAs($this->admin)->post(route('operator.vouchers.void', $voucher->id))->assertSessionHasNoErrors();

        $this->assertSame('voided', $voucher->fresh()->status);
        $this->assertSame('disconnecting', $session->fresh()->status);
        $this->assertTrue($session->fresh()->pending_revocation);
    }

    public function test_voiding_a_connected_voucher_asks_the_site_agent_to_remove_the_router_user(): void
    {
        config(['site_agent.required' => true]);
        $site = Site::create(['tenant_id' => $this->tenant->id, 'name' => 'North']);
        Router::create(['tenant_id' => $this->tenant->id, 'site_id' => $site->id, 'name' => 'R1', 'status' => 'online']);
        SiteAgent::create(['tenant_id' => $this->tenant->id, 'site_id' => $site->id, 'name' => 'Agent', 'token_hash' => hash('sha256', 'tok'), 'last_seen_at' => now()]);
        $voucher = $this->voucher('unused', 'VOID-003');
        $session = app(TenantContext::class)->runAs($this->tenant, fn () => app(VoucherActivationService::class)
            ->activate($this->tenant, $voucher, Request::create('/'), '0771234567'));

        $this->actingAs($this->admin)->post(route('operator.vouchers.void', $voucher->id))->assertSessionHasNoErrors();

        $this->assertSame('pending', SiteAgentCommand::query()->where('operation', 'expire_user')->sole()->status);
        $this->assertNotSame('expired', $session->fresh()->status, 'the agent confirms the removal');
    }

    public function test_expired_session_pending_router_enforcement_is_visible_in_live_sessions(): void
    {
        $router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Session router',
            'management_ip' => '192.0.2.15',
        ]);
        $voucher = $this->voucher('redeemed', 'EXPIRED-PENDING');
        Session::create([
            'tenant_id' => $this->tenant->id,
            'router_id' => $router->id,
            'voucher_id' => $voucher->id,
            'package_id' => $this->package->id,
            'status' => 'disconnecting',
            'started_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
            'pending_revocation' => true,
            'pending_revocation_status' => 'expired',
        ]);

        $this->actingAs($this->admin)
            ->get('http://acme.localhost/operator/sessions')
            ->assertOk()
            ->assertSee('EXPIRED-PENDING')
            ->assertSee('Expired · awaiting router enforcement');
    }

    public function test_live_sessions_does_not_invent_network_metrics_for_untracked_vouchers(): void
    {
        $this->voucher('redeemed', 'UNTRACKED-VOUCHER');

        $this->actingAs($this->admin)
            ->get('http://acme.localhost/operator/sessions')
            ->assertOk();

        $this->assertDatabaseCount('sessions_log', 0);
    }

    public function test_a_voided_or_foreign_voucher_cannot_be_voided_again(): void
    {
        $voucher = $this->voucher('voided', 'VOID-004');
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $foreign = $this->voucher('unused', 'FOREIGN-1', $other);

        $this->actingAs($this->admin)->post(route('operator.vouchers.void', $voucher->id))->assertSessionHasErrors('error');
        $this->actingAs($this->admin)->post(route('operator.vouchers.void', $foreign->id))->assertNotFound();
        $this->assertSame('unused', $foreign->fresh()->status);
    }

    public function test_only_administrators_can_void_vouchers(): void
    {
        $voucher = $this->voucher();

        $this->actingAs($this->staff('operator_finance'))->post(route('operator.vouchers.void', $voucher->id))->assertForbidden();
        $this->assertSame('unused', $voucher->fresh()->status);
    }

    // ── Managing staff ──────────────────────────────────────────────────────

    public function test_an_administrator_can_change_a_colleagues_role(): void
    {
        $member = $this->staff('operator_support');

        $this->actingAs($this->admin)
            ->patch(route('operator.staff.update', $member->id), ['role' => 'operator_finance'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->hasRole('operator_finance'));
        $this->assertFalse($member->fresh()->hasRole('operator_support'));
        $log = AuditLog::where('action', 'staff.role_changed')->sole();
        $this->assertSame(['operator_support', 'operator_finance'], [$log->meta['from'], $log->meta['to']]);
    }

    public function test_unknown_roles_are_rejected(): void
    {
        $member = $this->staff('operator_support');

        $this->actingAs($this->admin)
            ->patch(route('operator.staff.update', $member->id), ['role' => 'platform_admin'])
            ->assertSessionHasErrors('role');

        $this->assertTrue($member->fresh()->hasRole('operator_support'));
    }

    public function test_you_cannot_change_or_deactivate_yourself(): void
    {
        $this->actingAs($this->admin)->patch(route('operator.staff.update', $this->admin->id), ['role' => 'operator_support'])->assertSessionHasErrors('error');
        $this->actingAs($this->admin)->post(route('operator.staff.deactivate', $this->admin->id))->assertSessionHasErrors('error');

        $this->assertTrue($this->admin->fresh()->hasRole('operator_admin'));
        $this->assertNull($this->admin->fresh()->deactivated_at);
    }

    public function test_the_last_active_administrator_cannot_be_removed_even_by_a_platform_admin(): void
    {
        $platform = User::factory()->create(['tenant_id' => null]);
        $platform->assignRole('platform_admin');

        $this->actingAs($platform)->withSession(['impersonate_tenant' => $this->tenant->id])
            ->post('http://acme.localhost/operator/staff/'.$this->admin->id.'/deactivate')
            ->assertSessionHasErrors('error');
        $this->actingAs($platform)->withSession(['impersonate_tenant' => $this->tenant->id])
            ->patch('http://acme.localhost/operator/staff/'.$this->admin->id, ['role' => 'operator_support'])
            ->assertSessionHasErrors('error');

        $this->assertNull($this->admin->fresh()->deactivated_at);
        $this->assertTrue($this->admin->fresh()->hasRole('operator_admin'));
    }

    public function test_a_second_administrator_can_be_demoted_while_another_remains(): void
    {
        $second = $this->staff('operator_admin', 'Second Admin');

        $this->actingAs($this->admin)
            ->patch(route('operator.staff.update', $second->id), ['role' => 'operator_support'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($second->fresh()->hasRole('operator_support'));
    }

    public function test_deactivating_signs_the_person_out_on_their_next_request(): void
    {
        $member = $this->staff('operator_support', 'Sam Support');
        $oldRememberToken = $member->remember_token;

        $this->actingAs($this->admin)->post(route('operator.staff.deactivate', $member->id))->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertNotNull($fresh->deactivated_at);
        $this->assertNotSame($oldRememberToken, $fresh->remember_token);
        $this->assertSame(1, AuditLog::where('action', 'staff.deactivated')->count());

        auth()->logout();
        $this->actingAs($fresh)->get(route('operator.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_person_cannot_sign_in_and_can_again_once_reactivated(): void
    {
        $member = $this->staff('operator_support', 'Sam Support');
        $member->forceFill(['deactivated_at' => now()])->save();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($this->admin)->post(route('operator.staff.reactivate', $member->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertNull($member->fresh()->deactivated_at);
        $this->assertSame(1, AuditLog::where('action', 'staff.reactivated')->count());

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member->fresh());
    }

    public function test_staff_management_is_limited_to_administrators_of_the_same_business(): void
    {
        $member = $this->staff('operator_support');
        $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);
        $outsider = $this->staff('operator_admin', 'Olga Outsider', $other);

        $this->actingAs($this->staff('operator_finance', 'Fiona Finance'))
            ->post(route('operator.staff.deactivate', $member->id))->assertForbidden();

        URL::defaults(['tenant' => 'beta']);
        $this->actingAs($outsider)->post(route('operator.staff.deactivate', $member->id))->assertNotFound();
        $this->assertNull($member->fresh()->deactivated_at);
    }

    public function test_the_staff_page_offers_the_new_actions(): void
    {
        $member = $this->staff('operator_support', 'Sam Support');

        $this->actingAs($this->admin)->get(route('operator.staff.index'))
            ->assertOk()->assertSee('Sam Support')->assertSee('Deactivate')->assertSee('This is you');
    }
}
