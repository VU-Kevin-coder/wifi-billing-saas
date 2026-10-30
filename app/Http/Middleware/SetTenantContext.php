<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(protected TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check if a Platform Admin is impersonating a tenant
        if ($request->user() && $request->user()->hasRole('platform_admin') && session()->has('impersonate_tenant')) {
            $tenant = \App\Models\Tenant::find(session('impersonate_tenant'));
            if ($tenant) {
                $this->context->setTenant($tenant);
                return $next($request);
            }
        }

        // 2. Otherwise, if authenticated and user has a tenant, set it
        if ($request->user() && $request->user()->tenant_id) {
            $this->context->setTenant($request->user()->tenant);
        }

        return $next($request);
    }
}
