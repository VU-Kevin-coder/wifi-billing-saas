<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\MfaSession;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MfaStepUpController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Enable Two-Factor Authentication to perform this action.');
        }

        return view('auth.mfa-step-up');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $code = trim($validated['code']);
        $verified = $user->hasEnabledTwoFactorAuthentication()
            && (preg_match('/^\d{6}$/', $code) === 1
                ? MFA::verifyTotp($user, $code)
                : MFA::verifyRecoveryCode($user, $code));

        if (! $verified) {
            AuditLog::record('mfa.step_up.failed', $user, [], $user);

            return back()->withErrors([
                'code' => 'The authentication or recovery code is invalid.',
            ]);
        }

        MfaSession::markVerified($request);
        AuditLog::record('mfa.step_up.verified', $user, [], $user);

        return redirect()->to($this->returnUrl($request))
            ->with('status', 'Identity confirmed. Please repeat your action.');
    }

    /**
     * Only ever return to a page on the host the user is already on.
     */
    private function returnUrl(Request $request): string
    {
        $target = $request->session()->pull('mfa.step_up_return');

        if (is_string($target) && parse_url($target, PHP_URL_HOST) === $request->getHost()) {
            return $target;
        }

        return route('dashboard');
    }
}
