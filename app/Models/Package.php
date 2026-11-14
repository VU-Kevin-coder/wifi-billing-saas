<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\DataLimit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Package extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'price',
        'currency',
        'duration_value',
        'duration_unit',
        'speed_limit_mbps',
        'device_limit',
        'data_limit',
        'radius_profile_name',
        'is_published',
        'is_popular',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
        'is_published' => 'boolean',
        'is_popular' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function getDurationUnitSingularAttribute(): string
    {
        return match (strtolower($this->duration_unit ?? '')) {
            'minutes', 'minute' => 'minute',
            'hours', 'hour' => 'hour',
            'days', 'day' => 'day',
            'weeks', 'week' => 'week',
            'months', 'month' => 'month',
            'years', 'year' => 'year',
            default => 'hour',
        };
    }

    public function expiresAt(CarbonInterface $startedAt): Carbon
    {
        $expiresAt = Carbon::instance($startedAt);
        $value = max(1, (int) ($this->duration_value ?: 2));

        return match ($this->duration_unit_singular) {
            'minute' => $expiresAt->addMinutes($value),
            'hour' => $expiresAt->addHours($value),
            'day' => $expiresAt->addDays($value),
            'week' => $expiresAt->addWeeks($value),
            'month' => $expiresAt->addMonths($value),
            'year' => $expiresAt->addYears($value),
        };
    }

    public function routerProfileName(): string
    {
        return 'package-'.$this->getKey();
    }

    /**
     * Return the canonical RouterOS HotSpot user profile settings for this package.
     */
    public function routerProfileSettings(): array
    {
        $speed = max(1, (int) $this->speed_limit_mbps);

        return [
            'name' => $this->routerProfileName(),
            'rate-limit' => "{$speed}M/{$speed}M",
            'shared-users' => max(1, (int) ($this->device_limit ?: 1)),
        ];
    }

    /**
     * RouterOS stores total uptime and byte caps on each HotSpot user, not on
     * the user profile. Keep those per-voucher limits in the user command.
     */
    public function routerLimitBytes(): ?int
    {
        if (! PlatformSetting::get('package.data_caps', true)) {
            return null;
        }

        try {
            return DataLimit::bytes($this->data_limit);
        } catch (\InvalidArgumentException) {
            Log::warning('Package has an unparseable data limit; no byte cap applied.', [
                'package_id' => $this->getKey(),
                'data_limit' => $this->data_limit,
            ]);

            return null;
        }
    }
}
