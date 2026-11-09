<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
