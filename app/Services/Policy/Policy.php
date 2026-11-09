<?php

namespace App\Services\Policy;

use App\Models\PolicySetting;
use App\Models\Tenant;

class Policy
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $setting = PolicySetting::where('key', $key)->first();
            if ($setting) {
                return self::$cache[$key] = $setting->value;
            }
        } catch (\Throwable) {
            return $default;
        }

        return $default;
    }

    public static function set(string $key, mixed $value, ?string $description = null): void
    {
        $category = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
        PolicySetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'category' => $category, 'description' => $description]
        );
        self::$cache = [];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Lifecycle policy check: does any applicable tenant state deny this action?
     */
    public static function denies(Tenant $tenant, string $action): bool
    {
        try {
            foreach (self::applicableStates($tenant) as $state) {
                $key = "lifecycle.{$state}.{$action}";
                if (PolicySetting::where('key', $key)->exists() && ! self::get($key, true)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private static function applicableStates(Tenant $tenant): array
    {
        $states = [];

        if ($tenant->status === 'suspended') {
            $states[] = 'suspended';
        }

        if ($tenant->kyc_status === 'pending') {
            $states[] = 'kyc_pending';
        } elseif ($tenant->kyc_status === 'rejected') {
            $states[] = 'kyc_rejected';
        }

        if ($tenant->subscription_status === 'past_due' && $tenant->subscription_ends_at?->isPast()) {
            $states[] = 'past_due';
        }

        if ($tenant->subscription_status === 'trial' && $tenant->trial_ends_at?->isPast()) {
            $states[] = 'trial_expired';
        }

        return $states;
    }
}
