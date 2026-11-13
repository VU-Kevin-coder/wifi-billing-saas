<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadiusAccountingSession extends Model
{
    protected $fillable = [
        'identity_id',
        'generation',
        'tenant_id',
        'router_id',
        'session_id',
        'state',
        'started_at',
        'last_accounting_at',
        'stopped_at',
        'input_bytes',
        'output_bytes',
    ];

    protected $casts = [
        'generation' => 'integer',
        'session_id' => 'integer',
        'started_at' => 'datetime',
        'last_accounting_at' => 'datetime',
        'stopped_at' => 'datetime',
        'input_bytes' => 'integer',
        'output_bytes' => 'integer',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(RadiusNasSessionIdentity::class, 'identity_id');
    }

    public function applicationSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RadiusAccountingEvent::class, 'accounting_session_id');
    }
}
