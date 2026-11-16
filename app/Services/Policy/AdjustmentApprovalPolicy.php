<?php

namespace App\Services\Policy;

use App\Models\PolicySetting;
use App\Models\User;

class AdjustmentApprovalPolicy
{
    public static function canApprove(User $approver, int $requestedBy): bool
    {
        try {
            $settings = PolicySetting::whereIn('key', [
                'approval.wallet_adjustment.min_admins',
                'approval.wallet_adjustment.self_approve',
            ])->get()->keyBy('key');
            $minAdmins = $settings->has('approval.wallet_adjustment.min_admins') ? $settings['approval.wallet_adjustment.min_admins']->value : 2;
            $selfApprove = $settings->has('approval.wallet_adjustment.self_approve') ? $settings['approval.wallet_adjustment.self_approve']->value : false;
            if (! is_int($minAdmins) || $minAdmins < 1 || $minAdmins > 5 || ! is_bool($selfApprove)) {
                return false;
            }
            $adminCount = User::withoutTenancy()->role('platform_admin')->count();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $adminCount >= $minAdmins && ($selfApprove === true || $approver->id !== $requestedBy);
    }
}
