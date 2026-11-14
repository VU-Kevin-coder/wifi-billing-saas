<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Router extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'site_id',
        'name',
        'wifi_ssid',
        'serial_number',
        'hardware_model',
        'management_ip',
        'site',
        'status',
        'cpu_usage',
        'memory_usage',
        'active_users',
        'last_seen_at',
        'telemetry_at',
        'uptime',
        'api_username',
        'api_password',
        'api_port',
        'has_wireless',
        'enable_hotspot',
        'hotspot_config',
        'ethernet_ports',
    ];

    protected $casts = [
        'api_password' => 'encrypted',
        'radius_shared_secret' => 'encrypted',
        'last_seen_at' => 'datetime',
        'telemetry_at' => 'datetime',
        'has_wireless' => 'boolean',
        'enable_hotspot' => 'boolean',
        'hotspot_config' => 'array',
        'ethernet_ports' => 'array',
        'api_port' => 'integer',
        'cpu_usage' => 'integer',
        'memory_usage' => 'integer',
        'active_users' => 'integer',
    ];

    protected $hidden = [
        'radius_shared_secret',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function vpnPeer(): HasOne
    {
        return $this->hasOne(VpnPeer::class);
    }

    /**
     * The secret is intentionally not mass assignable or serializable. Callers
     * that opt a router in must use RadiusRouterCredentialService and deliver
     * its returned value once through an independently authorized workflow.
     */
    public function hasUsableRadiusConfiguration(): bool
    {
        if ($this->access_backend !== 'radius'
            || ! is_string($this->radius_nas_identifier)
            || trim($this->radius_nas_identifier) === '') {
            return false;
        }

        try {
            $secret = $this->radius_shared_secret;
        } catch (DecryptException) {
            return false;
        }

        return is_string($secret) && strlen($secret) >= 64;
    }
}
