<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the trait: register the global scope and the creating hook.
     */
    public static function bootBelongsToTenant(): void
    {
        // Apply global scope so ALL queries are auto-filtered
        static::addGlobalScope(new TenantScope());

        // Auto-populate tenant_id on create
        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if ($context->hasTenant() && $model->tenant_id === null) {
                $model->tenant_id = $context->getTenantId();
            }
        });
    }

    /**
     * Every tenant-scoped model belongs to a tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Explicitly remove only the tenant boundary for platform-level queries.
     */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}