<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->getTenant();

        if (! $user || ! $tenant || ! ($tenant->settings['require_2fa'] ?? false)) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()
            && ! $request->routeIs('profile.security.*', 'logout')) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Your organization requires Two-Factor Authentication. Please set it up to continue.');
        }

        return $next($request);
    }
}
