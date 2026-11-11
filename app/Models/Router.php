<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
        'serial_number',
        'hardware_model',
        'management_ip',
        'site',
        'status',
        'cpu_usage',
        'memory_usage',
        'active_users',
        'last_seen_at',
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
        'last_seen_at' => 'datetime',
        'has_wireless' => 'boolean',
        'enable_hotspot' => 'boolean',
        'hotspot_config' => 'array',
        'ethernet_ports' => 'array',
        'api_port' => 'integer',
        'cpu_usage' => 'integer',
        'memory_usage' => 'integer',
        'active_users' => 'integer',
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
}
