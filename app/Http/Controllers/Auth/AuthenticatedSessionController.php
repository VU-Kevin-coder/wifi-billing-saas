<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        // 1. If user is a platform admin, send to platform dashboard
        if ($user->hasRole('platform_admin')) {
            return redirect()->intended(route('platform.dashboard', absolute: false));
        }

        // 2. If user is an operator, route to their specific tenant subdomain
        $tenantSlug = $user->tenant?->slug;
        if ($tenantSlug) {
            $currentHost = explode(':', $request->getHost())[0];
            $targetHost = "{$tenantSlug}.localhost";

            // If already on this subdomain, go directly to the operator dashboard
            if ($currentHost === $targetHost) {
                return redirect()->intended(route('operator.dashboard'));
            }

            // If logged in from main domain (or another domain), transfer authentication via signed SSO
            $redirectUrl = URL::temporarySignedRoute(
                'operator.sso.verify',
                now()->addMinutes(2),
                ['tenant' => $tenantSlug, 'user' => $user->id]
            );

            return redirect()->to($redirectUrl);
        }

        return redirect()->intended(route('operator.dashboard'));
    }

    /**
     * Complete single sign-on cross-domain authentication transfer.
     */
    public function ssoVerify(Request $request, TenantContext $context): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired authentication transfer link.');
        }

        $userId = $request->query('user');
        $user = User::withoutGlobalScopes()->findOrFail($userId);

        $tenant = $context->getTenant();
        if (! $tenant) {
            $slug = $request->route('tenant');
            $tenant = Tenant::where('slug', $slug)->firstOrFail();
            $context->setTenant($tenant);
        }

        // Validate user belongs to this tenant, or is a platform admin impersonating
        if ($user->tenant_id !== $tenant->id && ! $user->hasRole('platform_admin')) {
            abort(403, 'You do not belong to this workspace.');
        }

        // If impersonation was requested by platform admin
        if ($request->query('impersonate') && $user->hasRole('platform_admin')) {
            session(['impersonate_tenant' => $tenant->id]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('operator.dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
