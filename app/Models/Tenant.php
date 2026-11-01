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
            'domain' => $this->slug.'.localhost:8000',
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
            'sms_gateway' => 'mambo',
            'sms_api_key' => '',
            'sms_sender_id' => '',
            'sms_enabled' => false,
            'notify_voucher_sms' => true,
            'notify_credentials_sms' => true,
            'notify_receipt_sms' => true,
            'notify_expiry_sms' => true,
            'notify_expiry_sms_reminder' => true,
            'notify_expiry_reminder_mins' => 30,
            'template_voucher' => "Voucher: @password\nPackage: @package_name\nValidity: @validity\n\nConnect to WiFi and Enter this:\nSupport: @support_number",
            'template_expiry' => "@company_name: Your internet package has expired.\nPackage: @package_name\nPlease renew at @portal_url to continue using the internet.\nSupport: @support_number",
            'template_expiry_reminder' => "@company_name: Your internet package expires in @hours_left!\nPackage: @package_name\nExpires: @expiry_date\nRenew now at @portal_url\nSupport: @support_number",
            'whatsapp_enabled' => false,
            'whatsapp_gateway' => 'soprasend',
            'whatsapp_api_key' => '',
            'whatsapp_device_id' => '',
            'whatsapp_webhook_secret' => '',
            'whatsapp_chatbot_enabled' => false,
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

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
