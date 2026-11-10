<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\MfaSession;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $tenant = app(TenantContext::class)->getTenant();

            if ($tenant && ($tenant->settings['require_2fa'] ?? false)) {
                return redirect()->route('profile.security.index')
                    ->with('error', 'Your organization requires Two-Factor Authentication. Please set it up to continue.');
            }

            return redirect()->route('dashboard');
        }

        $mfaMode = $request->session()->get('mfa_mode', $request->query('mode', 'totp'));

        return view('auth.two-factor-challenge', [
            'mfaMode' => $mfaMode === 'recovery' ? 'recovery' : 'totp',
        ]);
    }

    public function store(Request $request, AuthenticatedSessionController $sessionController): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $code = trim($validated['code']);
        $verified = preg_match('/^\d{6}$/', $code) === 1
            ? MFA::verifyTotp($user, $code)
            : MFA::verifyRecoveryCode($user, $code);

        if (! $verified) {
            return back()->withErrors([
                'code' => 'The authentication or recovery code is invalid.',
            ])->with('mfa_mode', preg_match('/^\d{6}$/', $code) === 1 ? 'totp' : 'recovery');
        }

        $request->session()->regenerate();
        MfaSession::markVerified($request);

        return $sessionController->redirectAfterLogin($request, $user, mfaVerified: true);
    }
}
