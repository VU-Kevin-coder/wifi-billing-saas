<?php

namespace App\Models\Scopes;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * If a tenant context is active, filter by tenant_id.
     * If no tenant context (platform admin), do not filter (global access).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->hasTenant()) {
            $builder->where(
                $model->getTable() . '.tenant_id',
                $context->getTenantId()
            );
        }
    }
}