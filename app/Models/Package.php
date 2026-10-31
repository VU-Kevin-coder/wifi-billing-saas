<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use HasFactory, BelongsToTenant;

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
}
