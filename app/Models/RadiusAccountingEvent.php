<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadiusAccountingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'accounting_session_id',
        'fingerprint',
        'status_type',
        'event_time',
        'session_time',
        'input_octets',
        'input_gigawords',
        'output_octets',
        'output_gigawords',
        'termination_cause',
        'ip_address',
        'mac_address',
        'is_quarantined',
        'quarantine_reason',
        'received_at',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'received_at' => 'datetime',
        'session_time' => 'integer',
        'input_octets' => 'integer',
        'input_gigawords' => 'integer',
        'output_octets' => 'integer',
        'output_gigawords' => 'integer',
        'is_quarantined' => 'boolean',
    ];

    public function accountingSession(): BelongsTo
    {
        return $this->belongsTo(RadiusAccountingSession::class, 'accounting_session_id');
    }
}
