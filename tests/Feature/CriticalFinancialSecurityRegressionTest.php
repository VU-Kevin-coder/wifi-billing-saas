<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PendingAdjustment;
use App\Models\PlatformSetting;
use App\Models\PolicySetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Policy\AdjustmentService;
use App\Services\Policy\Policy;
use App\Services\Policy\PolicyRead;
use Database\Seeders\PolicySeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CriticalFinancialSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $first;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PolicySeeder::class);
        Role::findOrCreate('platform_admin', 'web');
        $this->first = User::factory()->create(['tenant_id' => null]);
        $this->first->assignRole('platform_admin');
        $this->second = User::factory()->create(['tenant_id' => null]);
        $this->second->assignRole('platform_admin');
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active', 'wallet_balance' => 10000]);
        Policy::flush();
    }

    private function requestAdjustment(): array
    {
        return app(AdjustmentService::class)->request($this->tenant, 100, 'Test adjustment', $this->first);
    }

    public function test_valid_policy_requires_single_second_admin_approval(): void
    {
        $result = $this->requestAdjustment();
        $this->assertSame('pending', $result['status']);
        $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
        $this->assertFalse(app(AdjustmentService::class)->approve($result['adjustment'], $this->first)['ok']);
        $this->assertTrue(app(AdjustmentService::class)->approve($result['adjustment'], $this->second)['ok']);
        $this->assertSame(10100, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_invalid_decision_policies_never_automatically_adjust(): void
    {
        foreach ([
            ['enabled', []], ['enabled', 'false'], ['enabled', 0],
            ['mode', 'invalid'], ['threshold', '100'], ['threshold', 1.5], ['threshold', -1], ['threshold', 100000001],
        ] as [$key, $value]) {
            PolicySetting::query()->delete();
            $this->seed(PolicySeeder::class);
            Policy::set('approval.wallet_adjustment.'.$key, $value);
            $this->assertSame('blocked', $this->requestAdjustment()['status'], $key);
            $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
        }
        PolicySetting::query()->delete();
        $this->seed(PolicySeeder::class);
        Policy::set('approval.wallet_adjustment.enabled', true);
        DB::table('policy_settings')->where('key', 'approval.wallet_adjustment.enabled')->update(['value' => 'null']);
        $this->assertSame('blocked', $this->requestAdjustment()['status']);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_explicit_valid_auto_policies_still_work(): void
    {
        Policy::set('approval.wallet_adjustment.enabled', false);
        $this->assertSame('auto_applied', $this->requestAdjustment()['status']);
        Policy::set('approval.wallet_adjustment.enabled', true);
        Policy::set('approval.wallet_adjustment.mode', 'threshold');
        Policy::set('approval.wallet_adjustment.threshold', 101);
        $this->assertSame('auto_applied', $this->requestAdjustment()['status']);
        Policy::set('approval.wallet_adjustment.mode', 'never');
        $this->assertSame('auto_applied', $this->requestAdjustment()['status']);
    }

    public function test_invalid_self_approval_or_admin_minimum_cannot_approve(): void
    {
        $pending = $this->requestAdjustment()['adjustment'];
        foreach (['false', 1, []] as $value) {
            Policy::set('approval.wallet_adjustment.self_approve', $value);
            $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->first)['ok']);
            $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->second)['ok']);
        }
        Policy::set('approval.wallet_adjustment.self_approve', false);
        foreach ([0, '2', [], 6] as $value) {
            Policy::set('approval.wallet_adjustment.min_admins', $value);
            $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->second)['ok']);
        }
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_unavailable_policy_store_blocks_request_and_approval(): void
    {
        $pending = $this->requestAdjustment()['adjustment'];
        $level = DB::transactionLevel();
        $counts = [Transaction::withoutTenancy()->count(), AuditLog::count()];
        $faults = 0;
        $lastState = null;
        DB::connection()->beforeExecuting(function (string $query) use (&$faults, &$lastState): void {
            if (preg_match('/^select\b.*\bfrom\s+[`"]?policy_settings[`"]?/i', $query)) {
                $faults++;
                try {
                    DB::select('select * from __toqi_missing_policy_store_test');
                } catch (QueryException $e) {
                    $lastState = $e->errorInfo[0] ?? null;
                    throw $e;
                }
            }
        });
        $this->assertSame('blocked', $this->requestAdjustment()['status']);
        $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->second)['ok']);
        $this->assertGreaterThanOrEqual(2, $faults);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('42P01', $lastState); // actual PostgreSQL server error
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('select 1 as value')->value);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
        $this->assertSame($counts, [Transaction::withoutTenancy()->count(), AuditLog::count()]);
        $this->tenant->update(['name' => 'Outer transaction remains writable']);
    }

    public function test_unavailable_admin_count_denies_without_poisoning_outer_transaction(): void
    {
        $pending = $this->requestAdjustment()['adjustment'];
        $level = DB::transactionLevel();
        $faults = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$faults): void {
            if (str_contains($query, 'model_has_roles') && str_starts_with(strtolower($query), 'select')) {
                $faults++;
                DB::select('select * from __toqi_missing_roles_test');
            }
        });
        $this->assertFalse(app(AdjustmentService::class)->approve($pending, $this->second)['ok']);
        $this->assertSame(1, $faults);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('select 1 as value')->value);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
        $this->tenant->update(['name' => 'Still writable']);
    }

    public function test_fresh_maximum_applies_to_requests_but_does_not_recheck_existing_approvals(): void
    {
        Policy::set('wallet.max_adjustment', 1000000);
        $this->assertSame(1000000, Policy::get('wallet.max_adjustment'));
        $pending = $this->requestAdjustment()['adjustment'];
        DB::table('policy_settings')->where('key', 'wallet.max_adjustment')->update(['value' => json_encode(50)]);
        $this->assertSame('blocked', $this->requestAdjustment()['status']);
        // Current approval semantics do not re-evaluate the maximum after a request.
        $this->assertTrue(app(AdjustmentService::class)->approve($pending, $this->second)['ok']);
        $this->assertSame(10100, (int) $this->tenant->fresh()->wallet_balance);
    }

    public function test_policy_read_propagates_dangerous_database_errors(): void
    {
        foreach (['40001', '40P01', '08006', '25P02', 'XX000'] as $state) {
            $pdoError = new \PDOException('simulated '.$state);
            $pdoError->errorInfo = [$state, 0, 'simulated'];
            $error = new QueryException(DB::connection()->getName(), 'select 1', [], $pdoError);
            try {
                PolicyRead::run(fn () => throw $error);
                $this->fail('Must propagate '.$state);
            } catch (QueryException $caught) {
                $this->assertSame($error, $caught);
            }
            $this->assertSame(1, (int) DB::selectOne('select 1 as value')->value);
        }
        try {
            PolicyRead::run(fn () => throw new \RuntimeException('deadlock detected'));
            $this->fail('Nested deadlock must escape');
        } catch (DeadlockException $e) {
            $this->assertStringContainsString('deadlock detected', $e->getMessage());
        }
    }

    public function test_failed_savepoint_rollback_is_propagated(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific rollback failure test.');
        }
        $base = DB::transactionLevel();
        DB::beginTransaction();
        try {
            try {
                PolicyRead::run(function () {
                    $inner = DB::transactionLevel();
                    DB::statement('RELEASE SAVEPOINT trans'.$inner);
                    DB::select('select * from __toqi_missing_rollback_test');
                });
                $this->fail('A failed rollback must escape.');
            } catch (\PDOException $e) {
                $this->assertNotSame('42P01', $e->errorInfo[0] ?? null);
            }
        } finally {
            DB::rollBack($base);
        }
        $this->assertSame($base, DB::transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('select 1 as value')->value);
    }

    public function test_mandatory_events_bypass_disabled_optional_audit(): void
    {
        PlatformSetting::set('audit.enabled', false);
        foreach (['auth.login_failed', 'mfa.step_up.failed', 'tenant.impersonation.started', 'staff.role_changed', 'router.radius_credential_provisioned', 'platform.config.updated', 'policy.security.updated', 'wallet.adjusted', 'adjustment.requested', 'withdrawal.approved', 'refund.approved'] as $action) {
            AuditLog::record($action);
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        AuditLog::record('package.updated');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'package.updated']);
    }

    public function test_adjustment_creation_rolls_back_if_mandatory_audit_fails(): void
    {
        PlatformSetting::set('audit.enabled', false);
        AuditLog::creating(function ($log) {
            if ($log->action === 'adjustment.requested') {
                throw new \RuntimeException('audit unavailable');
            }
        });
        try {
            $this->requestAdjustment();
            $this->fail('Audit failure must abort request');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame(0, PendingAdjustment::withoutTenancy()->count());
    }

    public function test_wallet_application_rolls_back_if_audit_fails(): void
    {
        Policy::set('approval.wallet_adjustment.enabled', false);
        PlatformSetting::set('audit.enabled', false);
        AuditLog::creating(function ($log) {
            if ($log->action === 'wallet.adjusted') {
                throw new \RuntimeException('audit unavailable');
            }
        });
        try {
            $this->requestAdjustment();
            $this->fail('Audit failure must abort application');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertSame(10000, (int) $this->tenant->fresh()->wallet_balance);
        $this->assertSame(0, Transaction::withoutTenancy()->where('type', 'adjustment')->count());
    }

    public function test_validation_does_not_flash_known_credential_fields(): void
    {
        $this->actingAs($this->first);
        $fields = ['pesapal_consumer_key', 'pesapal_consumer_secret', 'flutterwave_public_key', 'flutterwave_secret_key', 'flutterwave_encryption_key', 'flutterwave_webhook_secret', 'mtn_subscription_key', 'mtn_api_user', 'mtn_api_key', 'airtel_client_id', 'airtel_client_secret', 'smtp_password', 'sms_api_key', 'whatsapp_api_key', 'whatsapp_webhook_secret', 'api_password', 'mikrotik_password', 'password', 'current_password', 'password_confirmation', 'token', 'code', 'email_code', 'recovery_code'];
        $payload = array_fill_keys($fields, 'sensitive-test-value');
        $payload['settings'] = ['sms.api_key' => 'nested-sensitive'];
        $payload['flutterwave_environment'] = 'invalid';
        $response = $this->put(route('platform.configuration.flutterwave.update'), $payload);
        $response->assertSessionHasErrors('flutterwave_environment');
        $old = session()->get('_old_input', []);
        foreach (array_merge($fields, ['settings']) as $field) {
            $this->assertArrayNotHasKey($field, $old, $field);
        }
        $this->assertSame('invalid', $old['flutterwave_environment']);
    }
}
