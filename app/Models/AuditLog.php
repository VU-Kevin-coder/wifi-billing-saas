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

    /**
     * Audit entries are append-only.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log entries cannot be modified.'));
        static::deleting(fn () => throw new \LogicException('Audit log entries cannot be deleted.'));
    }

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
        if (! PlatformSetting::get('audit.enabled', true)
            && ! str_starts_with($action, 'platform.settings.')) {
            return new self(['action' => $action]);
        }

        $request = request();
        $meta = PlatformSetting::get('audit.mask_pii', true)
            ? static::maskPhoneValues($meta)
            : $meta;

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

    private static function maskPhoneValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = static::maskPhoneValues($value);
            } elseif (is_string($value) && preg_match('/phone|mobile|recipient|destination/i', (string) $key)) {
                $digits = preg_replace('/\D+/', '', $value) ?? '';
                if (strlen($digits) >= 7) {
                    $values[$key] = '***'.substr($digits, -4);
                }
            }
        }

        return $values;
    }
}
