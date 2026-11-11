<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnPeer extends Model
{
    protected $fillable = [
        'tenant_id',
        'router_id',
        'name',
        'public_key',
        'private_key',
        'tunnel_ip',
        'enabled',
        'last_handshake_at',
        'config_downloaded_at',
    ];

    protected $hidden = ['private_key'];

    protected $casts = [
        'private_key' => 'encrypted',
        'enabled' => 'boolean',
        'last_handshake_at' => 'datetime',
        'config_downloaded_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
