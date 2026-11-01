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
        'settings',
        'mikrotik_host',
        'mikrotik_username',
        'mikrotik_password',
        'mikrotik_port',
    ];

    protected $casts = [
        'settings' => 'array',
        'mikrotik_password' => 'encrypted',
        'mikrotik_port' => 'integer',
    ];

    /**
     * Get tenant settings with defaults if not set.
     */
    public function getSettingsAttribute($value): array
    {
        $defaults = [
            'brand_name' => $this->name,
            'logo_path' => null,
            'primary_color' => '#3B82F6',
            'support_phone' => '+256700000000',
            'support_email' => '',
            'whatsapp_number' => '',
            'domain' => $this->slug . '.localhost:8000',
            'show_packages' => true,
            'customer_id_prefix' => 'KL',
            'voucher_prefix' => 'KL',
            'voucher_length' => 8,
            'printed_voucher_length' => 8,
            'voucher_format' => 'alphanumeric',
            'prune_inactive_days' => 30,
            'auto_reconnect_method' => 'mac_cookie',
            'device_remember_days' => 30,
            'extend_gateway_fee' => false,
            'enable_agent_portal' => false,
            'enable_agent_reselling' => false,
            'enable_pppoe_self_registration' => false,
            'block_tethering' => true,
            'tethering_action' => 'block',
            'enable_2fa' => false,
            'require_2fa' => false,
            'trust_device_days' => 30,
            'secondary_color' => '#ffffff',
            'template' => 'rose',
            'portal_title' => $this->name ?? 'Wi-Fi Hotspot',
            'portal_subtitle' => 'Connect to the internet',
            'show_device_registration' => true,
            'show_troubleshooting' => true,
        ];

        $storedSettings = is_array($value) ? $value : (json_decode($value ?? '', true) ?? []);

        return array_merge($defaults, $storedSettings);
    }

    /**
     * All users (staff) belonging to this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
