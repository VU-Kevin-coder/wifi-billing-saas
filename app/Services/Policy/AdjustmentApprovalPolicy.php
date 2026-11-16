<?php

namespace App\Services\Policy;

use App\Models\PolicySetting;
use App\Models\User;

class AdjustmentApprovalPolicy
{
    /** Return only a complete, valid policy; missing or damaged rows are not authorization. */
    public static function settings(): ?array
    {
        $prefix = 'approval.wallet_adjustment.';
        $keys = ['enabled', 'mode', 'threshold', 'self_approve', 'expiry_hours', 'min_admins'];

        // Policy::get masks store errors and supplies defaults, neither is safe here.
        [$ok, $rows] = PolicyRead::run(fn () => PolicySetting::whereIn('key', array_map(fn ($key) => $prefix.$key, $keys))->get()->keyBy('key'));
        if (! $ok) {
            return null;
        }
        $values = [];
        foreach ($keys as $key) {
            if (! $rows->has($prefix.$key)) {
                return null;
            }
            $values[$key] = $rows[$prefix.$key]->value;
        }

        if (! is_bool($values['enabled']) || ! in_array($values['mode'], ['always', 'threshold', 'never'], true)
            || ! is_int($values['threshold']) || $values['threshold'] < 0 || $values['threshold'] > 100000000
            || ! is_bool($values['self_approve'])
            || ! is_int($values['expiry_hours']) || $values['expiry_hours'] < 1 || $values['expiry_hours'] > 336
            || ! is_int($values['min_admins']) || $values['min_admins'] < 1 || $values['min_admins'] > 5) {
            return null;
        }

        return $values;
    }

    public static function canApprove(User $approver, int $requestedBy): bool
    {
        $settings = self::settings();
        if ($settings === null) {
            return false;
        }

        [$ok, $adminCount] = PolicyRead::run(fn () => User::withoutTenancy()->role('platform_admin')->count());
        if (! $ok) {
            return false;
        }

        return $adminCount >= $settings['min_admins'] && ($settings['self_approve'] || $approver->id !== $requestedBy);
    }
}
