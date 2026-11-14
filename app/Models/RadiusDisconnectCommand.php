<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiusDisconnectCommand extends Model
{
    protected $fillable = [
        'command_id',
        'tenant_id',
        'router_id',
        'accounting_session_id',
        'idempotency_key',
        'state',
        'attempt_count',
        'available_at',
        'lease_owner',
        'lease_expires_at',
        'delivery_acknowledged_at',
        'delivery_result_code',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'available_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'delivery_acknowledged_at' => 'datetime',
    ];

    public function accountingSession(): BelongsTo
    {
        return $this->belongsTo(RadiusAccountingSession::class, 'accounting_session_id');
    }
}
