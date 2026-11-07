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

            // Redirect to billing so they can pay / update KYC
            $route = $tenant->slug ? route('operator.billing.index', ['tenant' => $tenant->slug]) : url('/');

            return redirect($route)
                ->with('error', 'Your account cannot perform this action right now. Please contact support or update your account status.');
        }

        return $next($request);
    }
}

