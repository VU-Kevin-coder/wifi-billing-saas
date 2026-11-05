<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an administrative or financial action for later review.
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?User $actor = null,
        ?Tenant $tenant = null,
    ): self {
        $request = request();

        return static::create([
            'tenant_id' => $tenant?->id ?? $subject?->tenant_id,
            'user_id' => $actor?->id ?? $request?->user()?->id,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'meta' => $meta ?: null,
        ]);
    }
}
