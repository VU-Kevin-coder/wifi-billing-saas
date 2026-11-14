<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteAgent extends Model
{
    protected $fillable = [
        'tenant_id',
        'site_id',
        'name',
        'token_hash',
        'last_seen_at',
        'offline_notified_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'offline_notified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(SiteAgentCommand::class);
    }
}
