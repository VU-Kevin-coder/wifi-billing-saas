<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadiusNasSessionIdentity extends Model
{
    protected $fillable = [
        'tenant_id',
        'router_id',
        'nas_session_id',
        'last_generation',
        'current_generation_id',
    ];

    protected $casts = [
        'last_generation' => 'integer',
        'current_generation_id' => 'integer',
    ];

    public function accountingSessions(): HasMany
    {
        return $this->hasMany(RadiusAccountingSession::class, 'identity_id');
    }
}
