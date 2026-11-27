<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaFactorManager;
use App\Support\MfaSession;
use App\Services\Tenancy\TenantContext;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
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

        /** @var User $user */
        $user = $request->user();
        $methods = $user->enabledTwoFactorMethods();
        $mfaMode = $request->query('mode', $request->session()->get('mfa_mode', $methods[0] ?? 'totp'));
        $recoveryAvailable = MFA::getRemainingRecoveryCodesCount($user) > 0;
        if ($mfaMode === 'recovery' && ! $recoveryAvailable) {
            $mfaMode = $methods[0] ?? 'totp';
        } elseif (! in_array($mfaMode, $methods, true)) {
            $mfaMode = $methods[0] ?? 'totp';
        }

        return response()->view('auth.two-factor-challenge', [
            'mfaMode' => $mfaMode,
            'availableMethods' => $methods,
            'recoveryAvailable' => $recoveryAvailable,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendChallenge(Request $request, MfaFactorManager $mfaFactors): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:email,sms'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $method = $validated['method'];

        if (! MFA::isEnabled($user, $method)
            || ($method === 'email' && $user->email_verified_at === null)
            || ($method === 'sms' && ($user->phone_verified_at === null || blank($user->phone)))) {
            return back()->with('error', 'That verification method is unavailable for this account.');
        }

        try {
            if (! MFA::issueChallenge($user, $method)) {
                throw new \RuntimeException('The MFA delivery channel is unavailable.');
            }
        } catch (Throwable $exception) {
            $mfaFactors->deleteChallenges($user, $method);
            Log::warning('MFA sign-in code could not be sent.', [
                'user_id' => $user->getKey(),
                'method' => $method,
                'exception' => $exception::class,
            ]);

            return back()->with('error', 'We could not send the verification code. Try another method or contact support.');
        }

        $request->session()->put('mfa_mode', $method);

        return redirect()->route('two-factor.challenge')
            ->with('status', 'A verification code has been sent.');
    }

    public function store(Request $request, AuthenticatedSessionController $sessionController): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $code = trim($validated['code']);
        $method = $request->input('method');
        if (! in_array($method, ['totp', 'email', 'sms', 'recovery'], true)) {
            $method = preg_match('/^\d{6}$/', $code) === 1 ? 'totp' : 'recovery';
        }

        $verified = match ($method) {
            'totp' => MFA::isEnabled($user, 'totp') && MFA::verifyTotp($user, $code),
            'email', 'sms' => MFA::isEnabled($user, $method) && MFA::verifyChallenge($user, $method, $code),
            'recovery' => MFA::verifyRecoveryCode($user, $code),
        };

        if (! $verified) {
            $message = 'The verification or recovery code is invalid.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['code' => [$message]],
                ], 422, ['Cache-Control' => 'no-store, private']);
            }

            return back()->withErrors([
                'code' => $message,
            ])->with('mfa_mode', $method);
        }

        $request->session()->regenerate();
        MfaSession::markVerified($request);
        $request->session()->forget('mfa_mode');

        $redirect = $sessionController->redirectAfterLogin($request, $user, mfaVerified: true);

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $redirect->getTargetUrl(),
            ], 200, ['Cache-Control' => 'no-store, private']);
        }

        return $redirect;
    }
}
