<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\SsoNonce;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\MfaSession;
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

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('mfa.verified', false);

            return redirect()->route('two-factor.challenge');
        }

        return $this->redirectAfterLogin($request, $user);
    }

    /**
     * Finish authentication after the password and, when enabled, MFA checks.
     */
    public function redirectAfterLogin(Request $request, User $user, bool $mfaVerified = false): RedirectResponse
    {
        // 1. If user is a platform admin, send to platform dashboard
        if ($user->hasRole('platform_admin')) {
            return redirect()->intended(route('platform.dashboard', absolute: false));
        }

        // 2. If user is an operator, route to their specific tenant subdomain
        $tenant = $user->tenant;
        if ($tenant) {
            $currentHost = strtolower(explode(':', $request->getHost())[0]);
            $targetHost = strtolower(explode(':', $tenant->host())[0]);

            // If already on this subdomain, go directly to the operator dashboard
            if ($currentHost === $targetHost) {
                return redirect()->intended(route('operator.dashboard'));
            }

            // Transfer authentication with a signed, single-use server-side nonce.
            return redirect()->to($this->ssoUrl($user, $tenant, $mfaVerified));
        }

        return redirect()->intended(route('operator.dashboard'));
    }

    /**
     * Build a short-lived signed SSO URL backed by a one-time nonce.
     */
    public function ssoUrl(User $user, Tenant $tenant, bool $mfaVerified = false, bool $impersonation = false): string
    {
        $token = SsoNonce::issue($user, $tenant, $mfaVerified, $impersonation);

        return URL::temporarySignedRoute('operator.sso.verify', now()->addMinutes(2), [
            'tenant' => $tenant->slug,
            'user' => $user->id,
            'token' => $token,
        ]);
    }

    /**
     * Complete single sign-on cross-domain authentication transfer.
     */
    public function ssoVerify(Request $request, TenantContext $context): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired authentication transfer link.');
        }

        $tenant = $context->getTenant();
        if (! $tenant) {
            $tenant = Tenant::where('slug', (string) $request->route('tenant'))->firstOrFail();
            $context->setTenant($tenant);
        }

        $user = User::withoutTenancy()->findOrFail($request->integer('user'));

        // Validate user belongs to this tenant, or is a platform admin.
        if ($user->tenant_id !== $tenant->id && ! $user->hasRole('platform_admin')) {
            abort(403, 'You do not belong to this workspace.');
        }

        // The nonce is bound to the user and tenant and may only be used once,
        // even if the signed URL itself is replayed.
        $nonce = SsoNonce::consume((string) $request->query('token'), $user, $tenant);
        if (! $nonce) {
            abort(403, 'Invalid or expired authentication transfer link.');
        }

        if ($nonce->impersonation) {
            if (! $user->hasRole('platform_admin')) {
                abort(403, 'You are not allowed to impersonate this workspace.');
            }

            session(['impersonate_tenant' => $tenant->id]);
            AuditLog::record('tenant.impersonation.started', $tenant, [
                'platform_user_id' => $user->id,
            ], $user, $tenant);
        }

        $mfaVerified = $nonce->mfa_verified && $user->hasEnabledTwoFactorAuthentication();

        Auth::login($user);
        $request->session()->regenerate();
        if ($mfaVerified) {
            MfaSession::markVerified($request);
        } else {
            $request->session()->put('mfa.verified', false);
        }

        if ($user->hasEnabledTwoFactorAuthentication() && ! $mfaVerified) {
            return redirect()->route('two-factor.challenge');
        }

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
