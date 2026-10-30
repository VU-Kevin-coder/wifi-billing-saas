<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage in routes: ->middleware('role:operator_admin')
     * Or: ->middleware('role:platform_admin')
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        // If a platform admin is impersonating a tenant, allow access to operator areas
        if ($user && $user->hasRole('platform_admin') && session()->has('impersonate_tenant')) {
            return $next($request);
        }

        if (! $user || ! $user->hasRole($role)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
