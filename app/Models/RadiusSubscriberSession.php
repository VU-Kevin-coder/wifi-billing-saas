<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiusSubscriberSession extends Model
{
    protected $table = 'radius_subscriber_sessions';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'subscriber_id',
        'nas_session_id',
        'generation',
        'input_bytes',
        'output_bytes',
        'state',
        'started_at',
        'last_seen_at',
        'stopped_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'input_bytes' => 'integer',
        'output_bytes' => 'integer',
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
