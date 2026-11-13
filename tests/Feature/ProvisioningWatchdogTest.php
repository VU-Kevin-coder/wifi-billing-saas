<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\PlatformSetting;
use App\Models\Refund;
use App\Models\Router;
use App\Models\Session;
use App\Models\Site;
use App\Models\SiteAgent;
use App\Models\SiteAgentCommand;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Notifications\ProvisioningStuck;
use App\Services\ProvisioningWatchdog;
use App\Services\Tenancy\TenantContext;
use App\Services\VoucherActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProvisioningWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'watchdog-test-token';

    private Tenant $tenant;

    private SiteAgent $agent;

    private User $admin;

    private Session $session;

    private SiteAgentCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site_agent.required' => true]);
        Notification::fake();
        $this->freezeTime();

        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $this->tenant = Tenant::create(['name' => 'Agent Test', 'slug' => 'agent-test', 'status' => 'active']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('operator_admin');

        $site = Site::create(['tenant_id' => $this->tenant->id, 'name' => 'North Site']);
        Router::create(['tenant_id' => $this->tenant->id, 'site_id' => $site->id, 'name' => 'North Router', 'status' => 'online']);
        $this->agent = SiteAgent::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $site->id,
            'name' => 'North Site Agent',
            'token_hash' => hash('sha256', self::TOKEN),
            'last_seen_at' => now(),
        ]);
        $package = Package::create([
            'tenant_id' => $this->tenant->id, 'name' => 'One hour', 'price' => 1000, 'currency' => 'UGX',
            'duration_value' => 1, 'duration_unit' => 'hours', 'speed_limit_mbps' => 10, 'device_limit' => 1,
        ]);
        $batch = VoucherBatch::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id, 'batch_code' => 'WD-1', 'quantity' => 1,
        ]);
        $voucher = Voucher::create([
            'tenant_id' => $this->tenant->id, 'voucher_batch_id' => $batch->id,
            'package_id' => $package->id, 'code' => 'WD-001', 'status' => 'unused',
        ]);

        $this->session = app(TenantContext::class)->runAs($this->tenant, fn () => app(VoucherActivationService::class)
            ->activate($this->tenant, $voucher, Request::create('/'), '0771234567'));
        $this->command = SiteAgentCommand::query()->where('operation', 'provision_user')->sole();

        Notification::fake();
    }

    private function inspect(): string
    {
        return app(ProvisioningWatchdog::class)->inspect($this->session->fresh());
    }

    /** Pretend the customer paid $minutes ago (the package clock started then). */
    private function paidMinutesAgo(int $minutes): void
    {
        $this->session->update([
            'started_at' => now()->subMinutes($minutes),
            'expires_at' => now()->subMinutes($minutes)->addHour(),
        ]);
    }

    private function failCommand(int $minutesAgo = 10): void
    {
        $this->command->update(['status' => 'failed', 'attempts' => 5, 'failure_reason' => 'router unreachable']);
        $this->command->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }

    // ── Waiting and alerting ────────────────────────────────────────────────

    public function test_a_recent_pending_activation_is_left_alone(): void
    {
        $this->paidMinutesAgo(3);

        $this->assertSame('waiting', $this->inspect());
        Notification::assertNothingSent();
    }

    public function test_an_unanswered_activation_alerts_the_operator_exactly_once(): void
    {
        $this->paidMinutesAgo(11);

        $this->assertSame('alerted', $this->inspect());
        $this->assertSame('already_alerted', $this->inspect());

        Notification::assertSentToTimes($this->admin, ProvisioningStuck::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'provisioning.stuck')->count());
        $this->assertSame('agent_not_responding', AuditLog::where('action', 'provisioning.stuck')->first()->meta['reason']);
        $this->assertNotNull($this->session->fresh()->provisioning_alerted_at);
    }

    public function test_alerts_go_only_to_operator_admins_of_the_same_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $outsider = User::factory()->create(['tenant_id' => $other->id]);
        $outsider->assignRole('operator_admin');
        $this->paidMinutesAgo(11);

        $this->inspect();

        Notification::assertSentTo($this->admin, ProvisioningStuck::class);
        Notification::assertNotSentTo($outsider, ProvisioningStuck::class);
    }

    // ── Failed commands ─────────────────────────────────────────────────────

    public function test_failed_activation_is_requeued_when_the_agent_is_online(): void
    {
        $this->paidMinutesAgo(8);
        $this->failCommand(minutesAgo: 6);

        $this->assertSame('requeued', $this->inspect());

        $fresh = $this->command->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertSame('01:00:00', $fresh->payload['limit_uptime'], 'full package duration, since delay is compensated');
        $this->assertSame(1, $this->session->fresh()->provisioning_requeues);
        $this->assertSame(1, AuditLog::where('action', 'provisioning.requeued')->count());
        Notification::assertNothingSent();
    }

    public function test_requeue_waits_for_the_backoff_period(): void
    {
        $this->paidMinutesAgo(8);
        $this->failCommand(minutesAgo: 1);

        $this->assertSame('waiting', $this->inspect());
        $this->assertSame('failed', $this->command->fresh()->status);
    }

    public function test_failed_activation_with_an_offline_agent_alerts_instead_of_requeueing(): void
    {
        $this->paidMinutesAgo(8);
        $this->failCommand();
        $this->agent->update(['last_seen_at' => now()->subMinutes(30)]);

        $this->assertSame('alerted', $this->inspect());
        $this->assertSame('failed', $this->command->fresh()->status);
        $this->assertSame('agent_unhealthy', AuditLog::where('action', 'provisioning.stuck')->first()->meta['reason']);
    }

    public function test_after_the_retry_budget_the_operator_is_alerted(): void
    {
        $this->paidMinutesAgo(30);
        $this->failCommand();
        $this->session->update(['provisioning_requeues' => 3]);

        $this->assertSame('alerted', $this->inspect());
        $this->assertSame('retries_exhausted', AuditLog::where('action', 'provisioning.stuck')->first()->meta['reason']);
        $this->assertSame('failed', $this->command->fresh()->status);
    }

    public function test_a_missing_activation_command_is_recreated(): void
    {
        $this->paidMinutesAgo(4);
        $this->command->delete();

        $this->assertSame('recreated', $this->inspect());
        $this->assertSame('pending', SiteAgentCommand::query()->where('operation', 'provision_user')->sole()->status);
    }

    // ── Package expired before access ever worked ───────────────────────────

    public function test_expired_unprovisioned_session_is_closed_cleaned_up_and_reported(): void
    {
        $this->session->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('expired', $this->inspect());

        $this->assertSame('disconnecting', $this->session->fresh()->status);
        $this->assertSame('failed', $this->command->fresh()->status);
        $this->assertSame('pending', SiteAgentCommand::query()->where('operation', 'expire_user')->sole()->status,
            'the agent must remove a router user that may already exist');
        $this->assertSame(1, AuditLog::where('action', 'provisioning.expired_unprovisioned')->count());
        Notification::assertSentTo($this->admin, ProvisioningStuck::class, fn ($n) => $n->expired === true);
    }

    public function test_expired_unprovisioned_sale_creates_a_refund_request_when_enabled(): void
    {
        Transaction::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'platform_fee' => 50,
            'net_amount' => 950,
            'status' => 'completed',
            'reference' => 'WD-AUTO-REFUND',
            'phone' => '0771234567',
            'meta' => ['voucher_id' => $this->session->voucher_id],
        ]);
        $this->session->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('expired', $this->inspect());

        $refund = Refund::withoutTenancy()->sole();
        $this->assertSame('pending', $refund->status);
        $this->assertSame(1000.0, (float) $refund->amount);
        $this->assertStringContainsString('session #'.$this->session->id, $refund->reason);
        $this->assertSame(1, AuditLog::where('action', 'refund.auto_requested')->count());
    }

    public function test_auto_refund_request_can_be_disabled(): void
    {
        PlatformSetting::set('refunds.auto_request', false);
        Transaction::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'voucher_sale',
            'amount' => 1000,
            'platform_fee' => 50,
            'net_amount' => 950,
            'status' => 'completed',
            'reference' => 'WD-NO-AUTO-REFUND',
            'phone' => '0771234567',
            'meta' => ['voucher_id' => $this->session->voucher_id],
        ]);
        $this->session->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('expired', $this->inspect());
        $this->assertSame(0, Refund::withoutTenancy()->count());
    }

    public function test_a_late_agent_confirmation_cannot_revive_an_expired_session(): void
    {
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/site-agent/commands')->assertOk();
        $this->assertSame('claimed', $this->command->fresh()->status);
        $this->session->update(['expires_at' => now()->subMinute()]);

        $this->inspect();

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson("/api/site-agent/commands/{$this->command->id}/complete", ['success' => true])
            ->assertStatus(409);
        $this->assertSame('disconnecting', $this->session->fresh()->status);
    }

    // ── Delay compensation ──────────────────────────────────────────────────

    private function claimAndComplete(): void
    {
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/site-agent/commands')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson("/api/site-agent/commands/{$this->command->id}/complete", ['success' => true])
            ->assertOk();
    }

    public function test_a_delayed_activation_gives_the_full_package_duration(): void
    {
        $this->paidMinutesAgo(20);

        $this->claimAndComplete();

        $fresh = $this->session->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->provisioned_at);
        $this->assertTrue($fresh->expires_at->isSameSecond(now()->addHour()));
        $this->assertSame(1, AuditLog::where('action', 'provisioning.delay_compensated')->count());
    }

    public function test_a_prompt_activation_keeps_the_original_expiry(): void
    {
        $this->paidMinutesAgo(1);
        $expected = $this->session->fresh()->expires_at;

        $this->claimAndComplete();

        $fresh = $this->session->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->expires_at->equalTo($expected));
        $this->assertSame(0, AuditLog::where('action', 'provisioning.delay_compensated')->count());
    }

    public function test_compensation_can_be_switched_off(): void
    {
        config(['provisioning.compensate_delay' => false]);
        $this->paidMinutesAgo(20);
        $expected = $this->session->fresh()->expires_at;

        $this->claimAndComplete();

        $this->assertTrue($this->session->fresh()->expires_at->equalTo($expected));
    }

    // ── Command and scheduling ──────────────────────────────────────────────

    public function test_command_inspects_only_provisioning_sessions(): void
    {
        $this->paidMinutesAgo(11);
        $active = Session::withoutTenancy()->create([
            'tenant_id' => $this->tenant->id, 'status' => 'active',
            'started_at' => now()->subHours(3), 'expires_at' => now()->addHour(),
        ]);

        $this->artisan('provisioning:watchdog')->expectsOutputToContain('alerted=1')->assertSuccessful();

        $this->assertNull($active->fresh()->provisioning_alerted_at);
    }

    public function test_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('provisioning:watchdog');
    }
}
