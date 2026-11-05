<?php

namespace App\Auth;

use App\Models\Scopes\TenantScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Database\Eloquent\Builder;

class TenantAwareEloquentUserProvider extends EloquentUserProvider
{
    public function __construct(
        HasherContract $hasher,
        string $model,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct($hasher, $model);
    }

    protected function newModelQuery($model = null): Builder
    {
        $query = parent::newModelQuery($model);

        return $this->tenantContext->hasTenant()
            ? $query
            : $query->withoutGlobalScope(TenantScope::class);
    }
}
