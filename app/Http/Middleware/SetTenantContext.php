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
        // 1. Check if the domain itself is a tenant subdomain (e.g., acme.localhost)
        $host = $request->getHost();
        $tenantFromHost = $this->context->resolveFromHost($host);

        if ($tenantFromHost) {
            // Strict tenant boundary — ensure the logged-in user actually belongs to this tenant!
            // (Unless they are a platform admin impersonating)
            if ($request->user() && $request->user()->tenant_id !== $tenantFromHost->id && ! $request->user()->hasRole('platform_admin')) {
                abort(403, 'You do not have access to this workspace.');
            }

            $this->context->setTenant($tenantFromHost);
            return $next($request);
        }

        // 2. Check if a Platform Admin is impersonating a tenant via session
        if ($request->user() && $request->user()->hasRole('platform_admin') && session()->has('impersonate_tenant')) {
            $tenant = \App\Models\Tenant::find(session('impersonate_tenant'));
            if ($tenant) {
                $this->context->setTenant($tenant);
                return $next($request);
            }
        }

        // 3. Fallback: Set tenant based on the logged-in user's tenant_id
        if ($request->user() && $request->user()->tenant_id) {
            $this->context->setTenant($request->user()->tenant);
        }

        return $next($request);
    }
}
