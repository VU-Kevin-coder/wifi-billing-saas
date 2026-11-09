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
     * If no tenant context is active, fail closed. Platform-wide reads must
     * explicitly remove this scope with withoutTenancy().
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->getTable().'.tenant_id',
            $context->getTenantId()
        );
    }
}
