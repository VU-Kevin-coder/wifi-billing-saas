<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'portal_settings',
    ];

    protected $casts = [
        'portal_settings' => 'array',
    ];

    /**
     * Get portal settings with defaults if not set.
     */
    public function getPortalSettingsAttribute($value)
    {
        $defaults = [
            'primary_color' => '#d91c5c', // The Rose color from your screenshot
            'secondary_color' => '#ffffff',
            'template' => 'rose',
            'portal_title' => $this->name ?? 'Wi-Fi Hotspot',
            'portal_subtitle' => 'Connect to the internet',
            'show_packages' => true,
            'show_device_registration' => true,
            'show_troubleshooting' => true,
            'support_phone' => '+256 791346328',
        ];

        return array_merge($defaults, json_decode($value ?? '', true) ?? []);
    }

    /**
     * All users (staff) belonging to this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}