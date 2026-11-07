<?php

namespace Tests\Feature;

use App\Models\PendingAdjustment;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Policy\AdjustmentService;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdjustmentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin1;
    private User $admin2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PolicySeeder::class);

        Role::create(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'operator_admin', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'kyc_status' => 'verified', 'wallet_balance' => 100000,
            'subscription_status' => 'active',
        ]);

        $this->admin1 = User::factory()->create(['tenant_id' => null]);
        $this->admin1->assignRole('platform_admin');

        $this->admin2 = User::factory()->create(['tenant_id' => null]);
        $this->admin2->assignRole('platform_admin');
    }

    public function test_always_mode_creates_pending_adjustment(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(1, PendingAdjustment::withoutTenancy()->count());
        $this->assertSame(100000, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_threshold_mode_auto_applies_below_threshold(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'threshold');
        Policy::set('approval.wallet_adjustment.threshold', 10000);

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Small credit reason', $this->admin1);

        $this->assertSame('auto_applied', $result['status']);
        $this->assertSame(105000, (int) $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, PendingAdjustment::withoutTenancy()->count());
    }

    public function test_threshold_mode_requires_approval_above_threshold(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'threshold');
        Policy::set('approval.wallet_adjustment.threshold', 10000);

        $result = app(AdjustmentService::class)->request($this->tenant, 50000, 'Large credit reason', $this->admin1);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(100000, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_second_admin_can_approve(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);
        $adjustment = $result['adjustment'];

        $approval = app(AdjustmentService::class)->approve($adjustment, $this->admin2, 'OK');

        $this->assertTrue($approval['ok']);
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertSame(105000, (int) $this->tenant->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_self_approve_blocked_by_default(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');
        Policy::set('approval.wallet_adjustment.self_approve', false);

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);
        $adjustment = $result['adjustment'];

        $approval = app(AdjustmentService::class)->approve($adjustment, $this->admin1, 'OK');

        $this->assertFalse($approval['ok']);
        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(100000, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_self_approve_allowed_when_policy_enables(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');
        Policy::set('approval.wallet_adjustment.self_approve', true);

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);
        $adjustment = $result['adjustment'];

        $approval = app(AdjustmentService::class)->approve($adjustment, $this->admin1, 'OK');

        $this->assertTrue($approval['ok']);
    }

    public function test_negative_adjustment_below_zero_blocked_at_apply(): void
    {
        $this->expectException(\DomainException::class);

        app(AdjustmentService::class)->request($this->tenant, -200000, 'Too large debit reason', $this->admin1);
    }

    public function test_amount_over_max_blocked(): void
    {
        Policy::set('wallet.max_adjustment', 50000);

        $result = app(AdjustmentService::class)->request($this->tenant, 100000, 'Over cap reason', $this->admin1);

        $this->assertSame('blocked', $result['status']);
    }

    public function test_expired_adjustments_cannot_be_approved(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');
        Policy::set('approval.wallet_adjustment.expiry_hours', 1);

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);
        $adjustment = $result['adjustment'];

        $adjustment->update(['expires_at' => now()->subHour()]);

        $approval = app(AdjustmentService::class)->approve($adjustment, $this->admin2, 'OK');

        $this->assertFalse($approval['ok']);
    }

    public function test_admin_can_reject_pending_adjustment(): void
    {
        Policy::set('approval.wallet_adjustment.mode', 'always');

        $result = app(AdjustmentService::class)->request($this->tenant, 5000, 'Test credit reason', $this->admin1);
        $adjustment = $result['adjustment'];

        $rejection = app(AdjustmentService::class)->reject($adjustment, $this->admin2, 'Not justified');

        $this->assertTrue($rejection['ok']);
        $this->assertSame('rejected', $adjustment->fresh()->status);
        $this->assertSame('Not justified', $adjustment->fresh()->decision_reason);
        $this->assertSame(100000, (int) $this->tenant->fresh()->wallet_balance);
    }
}

