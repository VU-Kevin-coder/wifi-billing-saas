<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Support\MfaSession;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $isEnabled = $user->hasEnabledTwoFactorAuthentication();
        $totpMethod = MFA::getMethod($user, 'totp');
        $qrCode = ! $isEnabled && $totpMethod?->secret
            ? MFA::generateTotpQrCodeSvg($user)
            : null;
        $secret = $qrCode ? $totpMethod->secret : null;
        $recoveryCodes = session('recovery_codes', []);
        $remainingRecoveryCodes = $isEnabled
            ? MFA::getRemainingRecoveryCodesCount($user)
            : 0;

        return view('profile.security', compact(
            'isEnabled',
            'qrCode',
            'secret',
            'recoveryCodes',
            'remainingRecoveryCodes',
        ));
    }

    public function enable(): RedirectResponse
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security.index')
                ->with('status', 'Two-Factor Authentication is already enabled.');
        }

        if (! MFA::getMethod($user, 'totp')?->secret) {
            MFA::setupTotp($user);
        }

        MFA::disableMethod($user, 'totp');

        return redirect()->route('profile.security.index')
            ->with('status', 'Two-Factor Authentication setup started. Scan the QR code and confirm.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! MFA::verifyTotp($user, $validated['code'])) {
            return back()->withErrors([
                'code' => 'Invalid authentication code. Please try again.',
            ]);
        }

        $method = MFA::getMethod($user, 'totp');
        MFA::enableMethod($user, 'totp', ['secret' => $method->secret]);
        $recoveryCodes = MFA::generateRecoveryCodes($user);
        MfaSession::markVerified($request);

        return redirect()->route('profile.security.index')
            ->with('status', 'Two-Factor Authentication enabled successfully.')
            ->with('recovery_codes', $recoveryCodes);
    }
}
