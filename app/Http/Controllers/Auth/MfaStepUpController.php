<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaFactorManager;
use App\Support\MfaSession;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class MfaStepUpController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Enable Two-Factor Authentication to perform this action.');
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

        return response()->view('auth.mfa-step-up', [
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
            Log::warning('MFA step-up code could not be sent.', [
                'user_id' => $user->getKey(),
                'method' => $method,
                'exception' => $exception::class,
            ]);

            return back()->with('error', 'We could not send the verification code. Try another method or contact support.');
        }

        $request->session()->put('mfa_mode', $method);

        return redirect()->route('mfa.step-up')
            ->with('status', 'A verification code has been sent.');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
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

        $verified = $user->hasEnabledTwoFactorAuthentication() && match ($method) {
            'totp' => MFA::isEnabled($user, 'totp') && MFA::verifyTotp($user, $code),
            'email', 'sms' => MFA::isEnabled($user, $method) && MFA::verifyChallenge($user, $method, $code),
            'recovery' => MFA::verifyRecoveryCode($user, $code),
        };

        if (! $verified) {
            AuditLog::record('mfa.step_up.failed', $user, [], $user);
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

        MfaSession::markVerified($request);
        $request->session()->forget('mfa_mode');
        AuditLog::record('mfa.step_up.verified', $user, [], $user);

        $redirect = redirect()->to($this->returnUrl($request))
            ->with('status', 'Identity confirmed. Please repeat your action.');

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => $redirect->getTargetUrl(),
            ], 200, ['Cache-Control' => 'no-store, private']);
        }

        return $redirect;
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
