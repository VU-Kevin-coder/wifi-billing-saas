<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterCommand extends Model
{
    public const ALLOWED_OPERATIONS = ['kick'];

    public const ALLOWED_TARGET_SERVICES = ['hotspot', 'pppoe'];

    protected $fillable = [
        'command_id',
        'tenant_id',
        'router_id',
        'session_id',
        'subscriber_session_id',
        'operation',
        'target_service',
        'target_username',
        'idempotency_key',
        'state',
        'api_attempt_count',
        'poll_attempt_count',
        'available_at',
        'lease_owner',
        'lease_token_hash',
        'lease_expires_at',
        'delivered_via',
        'result_code',
    ];

    protected $hidden = [
        'lease_token_hash',
    ];

    protected $casts = [
        'api_attempt_count' => 'integer',
        'poll_attempt_count' => 'integer',
        'available_at' => 'datetime',
        'lease_expires_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function subscriberSession(): BelongsTo
    {
        return $this->belongsTo(RadiusSubscriberSession::class, 'subscriber_session_id');
    }
}
