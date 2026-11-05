<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SsoNonce extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'token_hash',
        'mfa_verified',
        'impersonation',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'mfa_verified' => 'boolean',
        'impersonation' => 'boolean',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Issue a single-use SSO token and return the plain token.
     */
    public static function issue(
        User $user,
        Tenant $tenant,
        bool $mfaVerified = false,
        bool $impersonation = false,
        int $ttlMinutes = 2,
    ): string {
        $token = Str::random(64);

        static::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $token),
            'mfa_verified' => $mfaVerified,
            'impersonation' => $impersonation,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $token;
    }

    /**
     * Atomically consume a token. Returns null when it is invalid, expired,
     * already used, or does not belong to the expected user/tenant.
     */
    public static function consume(string $token, User $user, Tenant $tenant): ?self
    {
        $nonce = static::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $nonce || $nonce->used_at !== null || $nonce->expires_at->isPast()) {
            return null;
        }

        $updated = static::query()
            ->whereKey($nonce->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $updated === 1 ? $nonce->fresh() : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
