<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PendingAdjustment;
use App\Models\PolicySetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Policy\AdjustmentService;
use App\Services\Policy\Policy;
use Database\Seeders\PolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdjustmentFailClosedRaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $requester;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PolicySeeder::class);
        Role::findOrCreate('platform_admin', 'web');
        $this->requester = User::factory()->create(['tenant_id' => null]);
        $this->requester->assignRole('platform_admin');
        $this->approver = User::factory()->create(['tenant_id' => null]);
        $this->approver->assignRole('platform_admin');
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'race-test', 'status' => 'active', 'wallet_balance' => 10000]);
    }

    private function request(): array
    {
        return app(AdjustmentService::class)->request($this->tenant, 100, 'Test adjustment', $this->requester);
    }

    public function test_every_missing_or_invalid_approval_row_blocks_requests_and_approval(): void
    {
        $pending = $this->request()['adjustment'];
        $invalid = [
            'enabled' => 'false', 'mode' => 'bad', 'threshold' => '100',
            'self_approve' => 'false', 'expiry_hours' => 0, 'min_admins' => '2',
        ];
        foreach ($invalid as $key => $badValue) {
            foreach (['missing', 'invalid'] as $kind) {
                PolicySetting::where('key', 'approval.wallet_adjustment.'.$key)->delete();
                if ($kind === 'invalid') {
                    Policy::set('approval.wallet_adjustment.'.$key, $badValue);
                }
                $this->assertSame('blocked', $this->request()['status'], "$key $kind request");
                $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->approver)['ok'], "$key $kind approval");
                PolicySetting::where('key', 'approval.wallet_adjustment.'.$key)->delete();
                $this->seed(PolicySeeder::class);
            }
        }
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertEquals(10000, $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_missing_rows_block_even_explicit_auto_modes(): void
    {
        foreach ([['enabled', false], ['mode', 'never'], ['mode', 'threshold']] as [$key, $value]) {
            $this->seed(PolicySeeder::class);
            Policy::set('approval.wallet_adjustment.'.$key, $value);
            Policy::set('approval.wallet_adjustment.threshold', 1000);
            PolicySetting::where('key', 'approval.wallet_adjustment.self_approve')->delete();
            $this->assertSame('blocked', $this->request()['status']);
            PolicySetting::where('key', 'like', 'approval.wallet_adjustment.%')->delete();
        }
        $this->assertEquals(10000, $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_stale_rejection_cannot_overwrite_approval_or_its_transaction(): void
    {
        $pending = $this->request()['adjustment'];
        $stale = PendingAdjustment::withoutTenancy()->findOrFail($pending->id);
        $service = app(AdjustmentService::class);
        $this->assertTrue($service->approve($pending, $this->approver, 'approved')['ok']);
        $this->assertFalse($service->reject($stale, $this->requester, 'rejected')['ok']);
        $row = $pending->fresh();
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->approver->id, $row->approved_by);
        $this->assertSame('approved', $row->decision_reason);
        $this->assertNotNull($row->transaction_id);
        $this->assertEquals(10100, $this->tenant->fresh()->wallet_balance);
        $this->assertSame(1, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
        $this->assertSame(0, AuditLog::where('action', 'adjustment.rejected')->count());
    }

    public function test_stale_approval_cannot_apply_after_rejection(): void
    {
        $pending = $this->request()['adjustment'];
        $stale = PendingAdjustment::withoutTenancy()->findOrFail($pending->id);
        $service = app(AdjustmentService::class);
        $this->assertTrue($service->reject($pending, $this->approver, 'rejected')['ok']);
        $this->assertFalse($service->approve($stale, $this->approver)['ok']);
        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->transaction_id);
        $this->assertEquals(10000, $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
        $this->assertSame(0, AuditLog::where('action', 'adjustment.approved')->count());
    }

    public function test_approval_checks_requester_on_locked_row(): void
    {
        $pending = $this->request()['adjustment'];
        PendingAdjustment::withoutTenancy()->whereKey($pending->id)->update(['requested_by' => $this->approver->id]);
        $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->approver)['ok']);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertEquals(10000, $this->tenant->fresh()->wallet_balance);
    }

    public function test_stale_rejection_cannot_overwrite_expiration(): void
    {
        $pending = $this->request()['adjustment'];
        PendingAdjustment::withoutTenancy()->whereKey($pending->id)->update(['expires_at' => now()->subHour()]);
        $service = app(AdjustmentService::class);
        $this->assertSame(1, $service->expireStale());
        $this->assertFalse($service->reject($pending, $this->approver, 'rejected')['ok']);
        $this->assertSame('expired', $pending->fresh()->status);
    }

    public function test_approval_is_rolled_back_if_approval_audit_fails(): void
    {
        $pending = $this->request()['adjustment'];
        AuditLog::creating(function ($log) {
            if ($log->action === 'adjustment.approved') {
                throw new \RuntimeException('audit unavailable');
            }
        });
        try {
            app(AdjustmentService::class)->approve($pending, $this->approver);
            $this->fail('Audit failure must roll back approval');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->transaction_id);
        $this->assertEquals(10000, $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_rejection_is_rolled_back_if_audit_fails(): void
    {
        $pending = $this->request()['adjustment'];
        AuditLog::creating(function ($log) {
            if ($log->action === 'adjustment.rejected') {
                throw new \RuntimeException('audit unavailable');
            }
        });
        try {
            app(AdjustmentService::class)->reject($pending, $this->approver, 'rejected');
            $this->fail('Audit failure must roll back rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->approved_by);
    }
}
