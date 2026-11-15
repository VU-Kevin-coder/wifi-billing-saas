<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiusDeviceReservation extends Model
{
    protected $fillable = [
        'identity_id',
        'generation',
        'tenant_id',
        'router_id',
        'session_id',
        'accounting_session_id',
        'state',
        'reserved_at',
        'bound_at',
        'released_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'session_id' => 'integer',
        'accounting_session_id' => 'integer',
        'reserved_at' => 'datetime',
        'bound_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(RadiusNasSessionIdentity::class, 'identity_id');
    }

    public function applicationSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function accountingSession(): BelongsTo
    {
        return $this->belongsTo(RadiusAccountingSession::class, 'accounting_session_id');
    }
}
