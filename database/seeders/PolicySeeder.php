<?php

namespace Database\Seeders;

use App\Services\Policy\Policy;
use Illuminate\Database\Seeder;

class PolicySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // ── Lifecycle (from Batch A) ──
            // Suspended tenants
            'lifecycle.suspended.portal_sell' => [false, 'Suspended tenants can sell vouchers'],
            'lifecycle.suspended.portal_redeem' => [false, 'Suspended tenants can redeem vouchers'],
            'lifecycle.suspended.dashboard' => [false, 'Suspended tenants can access the operator dashboard'],
            'lifecycle.suspended.withdraw' => [false, 'Suspended tenants can request withdrawals'],
            'lifecycle.suspended.generate_vouchers' => [false, 'Suspended tenants can generate vouchers'],
            'lifecycle.suspended.view_reports' => [true,  'Suspended tenants can view reports'],
            'lifecycle.suspended.view_billing' => [true,  'Suspended tenants can view billing to pay'],

            // KYC pending
            'lifecycle.kyc_pending.portal_sell' => [false, 'Tenants awaiting KYC can sell vouchers'],
            'lifecycle.kyc_pending.portal_redeem' => [false, 'Tenants awaiting KYC can redeem vouchers'],
            'lifecycle.kyc_pending.dashboard' => [true,  'Tenants awaiting KYC can access the operator dashboard'],
            'lifecycle.kyc_pending.withdraw' => [false, 'Tenants awaiting KYC can withdraw money'],
            'lifecycle.kyc_pending.generate_vouchers' => [true,  'Tenants awaiting KYC can generate vouchers'],
            'lifecycle.kyc_pending.view_reports' => [true,  'Tenants awaiting KYC can view reports'],

            // KYC rejected
            'lifecycle.kyc_rejected.portal_sell' => [false, 'KYC-rejected tenants can sell vouchers'],
            'lifecycle.kyc_rejected.portal_redeem' => [false, 'KYC-rejected tenants can redeem vouchers'],
            'lifecycle.kyc_rejected.dashboard' => [true,  'KYC-rejected tenants can access the dashboard'],
            'lifecycle.kyc_rejected.withdraw' => [false, 'KYC-rejected tenants can withdraw money'],
            'lifecycle.kyc_rejected.generate_vouchers' => [false, 'KYC-rejected tenants can generate vouchers'],
            'lifecycle.kyc_rejected.view_reports' => [true,  'KYC-rejected tenants can view reports'],

            // Past due
            'lifecycle.past_due.portal_sell' => [true,  'Past-due tenants can sell vouchers'],
            'lifecycle.past_due.portal_redeem' => [true,  'Past-due tenants can redeem vouchers'],
            'lifecycle.past_due.dashboard' => [true,  'Past-due tenants can access the dashboard'],
            'lifecycle.past_due.withdraw' => [false, 'Past-due tenants can withdraw money'],
            'lifecycle.past_due.generate_vouchers' => [true,  'Past-due tenants can generate vouchers'],
            'lifecycle.past_due.view_reports' => [true,  'Past-due tenants can view reports'],

            // Trial expired
            'lifecycle.trial_expired.portal_sell' => [false, 'Trial-expired tenants can sell vouchers'],
            'lifecycle.trial_expired.portal_redeem' => [true,  'Trial-expired tenants can redeem existing vouchers'],
            'lifecycle.trial_expired.dashboard' => [true,  'Trial-expired tenants can view the dashboard to pay'],
            'lifecycle.trial_expired.withdraw' => [false, 'Trial-expired tenants can withdraw money'],
            'lifecycle.trial_expired.generate_vouchers' => [false, 'Trial-expired tenants can generate vouchers'],
            'lifecycle.trial_expired.view_reports' => [true,  'Trial-expired tenants can view reports'],

            // Approval policy (used by Batch B)
            'approval.wallet_adjustment.enabled' => [true,     'Wallet adjustments require approval'],
            'approval.wallet_adjustment.mode' => ['always', 'always | threshold | never'],
            'approval.wallet_adjustment.threshold' => [0,        'Amount above which approval is required'],
            'approval.wallet_adjustment.self_approve' => [false,    'Can the requester approve their own adjustment'],
            'approval.wallet_adjustment.expiry_hours' => [24,       'Hours before a pending adjustment expires'],
            'approval.wallet_adjustment.min_admins' => [2,        'Minimum admins required for approval'],

            // ── Security ──
            'security.platform_admin_mfa' => [true, 'Require MFA for all platform admins'],
            'security.step_up_enabled' => [true, 'Require recent MFA for sensitive actions'],
            'security.step_up_minutes' => [10,   'How long an MFA verification is considered recent'],
            'security.password_reset_ttl' => [60,   'Password reset link validity (minutes)'],
            'security.login_throttle_attempts' => [6,    'Login attempts allowed per minute per IP+email'],
            'security.login_throttle_decay' => [1,    'Lockout window after failed attempts (minutes)'],

            // ── Wallet ──
            'wallet.max_adjustment' => [1000000, 'Maximum single manual wallet adjustment (UGX)'],
            'wallet.min_withdrawal' => [10000,   'Minimum amount an operator can withdraw (UGX)'],
            'wallet.withdrawal_manual_review_hours' => [24,      'Hours before an unmatched transfer is flagged for review'],
            'wallet.withdrawal_pending_limit' => [1,       'Maximum pending withdrawals per tenant'],

            // ── Provisioning ──
            'provisioning.alert_after_minutes' => [10,   'Minutes before alerting operator of a stuck session'],
            'provisioning.max_requeues' => [3,    'Automatic retries of failed activation command'],
            'provisioning.requeue_backoff_minutes' => [5,    'Minutes between retry attempts'],
            'provisioning.agent_healthy_minutes' => [5,    'Agent poll interval to be considered healthy'],
            'provisioning.compensate_delay' => [true, 'Give full package duration when activation is delayed'],
            'provisioning.grace_minutes' => [2,    'Grace period before delay compensation kicks in'],

            // ── Voucher ──
            'voucher.min_length' => [8,    'Minimum length for generated voucher codes'],
            'voucher.max_per_batch' => [1000, 'Maximum vouchers per generation request'],

            // ── Scheduling (changes require scheduler restart) ──
            'schedule.payment_reconcile_minutes' => [1,  'Payment reconciler frequency'],
            'schedule.withdrawal_reconcile_minutes' => [5,  'Withdrawal reconciler frequency'],
            'schedule.provisioning_watchdog_minutes' => [1,  'Provisioning watchdog frequency'],
            'schedule.adjustment_expiry_minutes' => [60, 'How often to sweep expired pending adjustments'],
        ];

        foreach ($defaults as $key => [$value, $description]) {
            Policy::set($key, $value, $description);
        }
    }
}
