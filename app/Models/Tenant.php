<?php

namespace App\Models;

use App\Support\TenantSettingsSecrets;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'status', 'settings',
        'mikrotik_host', 'mikrotik_username', 'mikrotik_password', 'mikrotik_port',
        'vpn_subnet',
        // Account details
        'legal_name', 'address', 'contact_name', 'contact_email', 'contact_phone',
        // Plan & Subscription
        'platform_plan_id', 'plan', 'plan_fee', 'trial_ends_at', 'subscription_ends_at', 'subscription_status', 'auto_renew',
        // Financial & Wallet
        'wallet_balance', 'commission_rate', 'kyc_status',
    ];

    protected $casts = [
        'settings' => 'array',
        'mikrotik_password' => 'encrypted',
        'mikrotik_port' => 'integer',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'auto_renew' => 'boolean',
        'plan_fee' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'commission_rate' => 'decimal:2',
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
            'domain' => $this->host(),
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

        return array_merge($defaults, TenantSettingsSecrets::decrypt($storedSettings));
    }

    public function setSettingsAttribute(array|string|null $value): void
    {
        $settings = is_array($value) ? $value : (json_decode($value ?? '', true) ?? []);
        $this->attributes['settings'] = json_encode(
            TenantSettingsSecrets::encrypt($settings),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The hostname used to reach this tenant's workspace.
     */
    public function host(): string
    {
        return $this->slug.'.'.self::domainSuffix();
    }

    public static function domainSuffix(): string
    {
        $host = (string) config('tenancy.base_domain');
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return $port && ! in_array((int) $port, [80, 443], true) ? $host.':'.$port : $host;
    }

    /**
     * Build an absolute URL for a path on this tenant's workspace domain.
     */
    public function url(string $path = '/'): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$this->host().'/'.ltrim($path, '/');
    }

    /**
     * Slugs that may never be used as tenant subdomains.
     */
    public static function reservedSlugs(): array
    {
        return config('tenancy.reserved_slugs', []);
    }

    public static function normalizeSlug(mixed $slug): string
    {
        return Str::slug(is_string($slug) ? $slug : '');
    }

    /**
     * All users (staff) belonging to this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function routers(): HasMany
    {
        return $this->hasMany(Router::class);
    }

    public function vpnPeers(): HasMany
    {
        return $this->hasMany(VpnPeer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'platform_plan_id');
    }

    public function getPlanAttribute($value)
    {
        if ($this->relationLoaded('plan')) {
            return $this->getRelation('plan');
        }

        return $value;
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
