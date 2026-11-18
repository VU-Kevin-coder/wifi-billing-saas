<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterOnboarding extends Model
{
    protected $fillable = [
        'tenant_id',
        'router_id',
        'bootstrap_token_hash',
        'bootstrap_script',
        'bootstrap_command',
        'status',
        'bootstrap_fetches',
        'expires_at',
        'connected_at',
        'completed_at',
    ];

    protected $hidden = [
        'bootstrap_token_hash',
        'bootstrap_script',
        'bootstrap_command',
    ];

    protected $casts = [
        'bootstrap_script' => 'encrypted',
        'bootstrap_command' => 'encrypted',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'completed_at' => 'datetime',
        'bootstrap_fetches' => 'integer',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
