<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAgentCommand extends Model
{
    protected $fillable = [
        'site_agent_id',
        'tenant_id',
        'router_id',
        'session_id',
        'operation',
        'payload',
        'status',
        'attempts',
        'claimed_at',
        'completed_at',
        'failure_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(SiteAgent::class, 'site_agent_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
