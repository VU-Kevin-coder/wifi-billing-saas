<?php

namespace App\Http\Middleware;

use App\Services\Policy\Policy;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next, string $action = 'dashboard'): Response
    {
        $tenant = app(TenantContext::class)->getTenant();

        if ($tenant && Policy::denies($tenant, $action)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Your account cannot perform this action. Please contact support.'], 403);
            }

            // Only redirect to an accessible billing page. Otherwise return a
            // denial directly, avoiding loops and redirects to a role-restricted page.
            if ($action === 'view_billing' || ! $tenant->slug
                || ! $request->user()?->hasAnyRole(['operator_admin', 'operator_finance'])
                || Policy::denies($tenant, 'view_billing')) {
                abort(403, 'Your account cannot perform this action. Please contact support.');
            }

            $route = route('operator.billing.index', ['tenant' => $tenant->slug]);

            return redirect($route)
                ->with('error', 'Your account cannot perform this action right now. Please contact support or update your account status.');
        }

        return $next($request);
    }
}
