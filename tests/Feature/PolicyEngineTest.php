<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Policy\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PolicySeeder::class);
    }

    public function test_withdrawal_min_reads_from_policy(): void
    {
        Policy::set('wallet.min_withdrawal', 50000);
        $this->assertSame(50000, Policy::get('wallet.min_withdrawal'));
    }

    public function test_voucher_max_per_batch_reads_from_policy(): void
    {
        Policy::set('voucher.max_per_batch', 500);
        $this->assertSame(500, Policy::get('voucher.max_per_batch'));
    }

    public function test_provisioning_settings_are_policy_driven(): void
    {
        Policy::set('provisioning.max_requeues', 5);
        $this->assertSame(5, Policy::get('provisioning.max_requeues'));
    }

    public function test_security_step_up_minutes_reads_from_policy(): void
    {
        Policy::set('security.step_up_minutes', 30);
        $this->assertSame(30, Policy::get('security.step_up_minutes'));
    }

    public function test_scheduling_settings_persist(): void
    {
        Policy::set('schedule.payment_reconcile_minutes', 2);
        $this->assertSame(2, Policy::get('schedule.payment_reconcile_minutes'));
    }

    public function test_all_expected_policy_keys_are_seeded(): void
    {
        $expected = [
            'security.platform_admin_mfa',
            'security.step_up_enabled',
            'security.step_up_minutes',
            'wallet.max_adjustment',
            'wallet.min_withdrawal',
            'wallet.withdrawal_manual_review_hours',
            'provisioning.alert_after_minutes',
            'provisioning.max_requeues',
            'provisioning.compensate_delay',
            'voucher.min_length',
            'voucher.max_per_batch',
            'schedule.payment_reconcile_minutes',
            'schedule.withdrawal_reconcile_minutes',
            'schedule.provisioning_watchdog_minutes',
        ];

        foreach ($expected as $key) {
            $this->assertNotNull(\App\Models\PolicySetting::where('key', $key)->first(), "Missing policy: {$key}");
        }
    }
}

