<?php

namespace App\Http\Middleware;

use App\Services\Policy\Policy;
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

        if (! $user) {
            return $next($request);
        }

        $mfaSetupRoutes = [
            'profile.security.*',
            'two-factor.challenge',
            'two-factor.verify',
            'two-factor.challenge.send',
            'mfa.step-up',
            'mfa.step-up.verify',
            'mfa.step-up.send',
            'logout',
        ];

        if ($user->mfa_enrollment_required_at
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ! $request->routeIs(...$mfaSetupRoutes)) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Your administrator reset two-factor authentication. Set it up again to continue.');
        }

        $mfaRequired = Policy::get('security.platform_admin_mfa', true)
            && config('security.platform_admin_mfa_required', true);

        if ($mfaRequired
            && $user->isPlatformAdmin()
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ! $request->routeIs(...$mfaSetupRoutes)) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Platform administrators must enable Two-Factor Authentication to continue.');
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if ($request->session()->get('mfa.verified') === true
                || $request->routeIs(
                    'two-factor.challenge',
                    'two-factor.challenge.send',
                    'two-factor.verify',
                    'mfa.step-up',
                    'mfa.step-up.verify',
                    'mfa.step-up.send',
                    'logout',
                )) {
                return $next($request);
            }

            return redirect()->route('two-factor.challenge');
        }

        if ($tenant && ($tenant->settings['require_2fa'] ?? false)
            && ! $request->routeIs(...$mfaSetupRoutes)) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Your organization requires Two-Factor Authentication. Please set it up to continue.');
        }

        return $next($request);
    }
}
