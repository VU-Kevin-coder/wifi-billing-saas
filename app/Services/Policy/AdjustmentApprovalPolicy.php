<?php

namespace App\Services\Policy;

use App\Models\User;

class AdjustmentApprovalPolicy
{
    public static function canApprove(User $approver, int $requestedBy): bool
    {
        $adminCount = User::withoutTenancy()->role('platform_admin')->count();
        $minAdmins = (int) Policy::get('approval.wallet_adjustment.min_admins', 2);

        if ($adminCount < $minAdmins) {
            return false;
        }

        if (Policy::get('approval.wallet_adjustment.self_approve', false)) {
            return true;
        }

        return $approver->id !== $requestedBy;
    }
}
