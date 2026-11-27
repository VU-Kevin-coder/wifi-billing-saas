<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaFactorManager;
use App\Services\Policy\Policy;
use App\Services\SmsService;
use App\Support\MfaSession;
use CodingLibs\MFA\Facades\MFA;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SecurityController extends Controller
{
    public function index(Request $request, MfaFactorManager $mfaFactors): View
    {
        /** @var User $user */
        $user = $request->user();
        $enabledMethods = $mfaFactors->enabledMethods($user);
        $pendingMethod = $request->session()->get('mfa.enrollment.method');
        $totpMethod = MFA::getMethod($user, 'totp');
        $qrCode = $pendingMethod === 'totp' && $totpMethod?->secret
            ? MFA::generateTotpQrCodeSvg($user)
            : null;
        $secret = $qrCode ? $totpMethod->secret : null;
        $recoveryCodes = $request->session()->get('recovery_codes', []);
        $remainingRecoveryCodes = $enabledMethods
            ? MFA::getRemainingRecoveryCodesCount($user)
            : 0;
        $mfaRequired = $this->isMfaRequired($user);
        $emailVerified = $user->email_verified_at !== null;
        $phoneAvailable = filled($user->phone);

        return view('profile.security', compact(
            'enabledMethods',
            'qrCode',
            'secret',
            'recoveryCodes',
            'remainingRecoveryCodes',
            'pendingMethod',
            'mfaRequired',
            'emailVerified',
            'phoneAvailable',
        ));
    }

    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (MFA::isEnabled($user, 'totp')) {
            return redirect()->route('profile.security.index')
                ->with('status', 'Authenticator app is already enabled.');
        }

        if ($redirect = $this->requireRecentMfa($request, $user)) {
            return $redirect;
        }

        MFA::setupTotp($user);
        MFA::disableMethod($user, 'totp');
        $request->session()->put('mfa.enrollment.method', 'totp');

        return redirect()->route('profile.security.index')
            ->with('status', 'Scan the QR code and confirm a code from your authenticator app.');
    }

    public function confirmTotp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);
        /** @var User $user */
        $user = $request->user();

        if ($redirect = $this->requireRecentMfa($request, $user)) {
            return $redirect;
        }

        if ($request->session()->get('mfa.enrollment.method') !== 'totp') {
            return back()->withErrors([
                'code' => 'The authenticator code is invalid or the setup has expired.',
            ]);
        }

        return DB::transaction(function () use ($request, $user, $validated): RedirectResponse {
            if (! MFA::verifyTotp($user, $validated['code'])) {
                return back()->withErrors([
                    'code' => 'The authenticator code is invalid or the setup has expired.',
                ]);
            }

            MFA::enableMethod($user, 'totp', [
                'secret' => MFA::getMethod($user, 'totp')->secret,
            ]);
            $request->session()->forget('mfa.enrollment.method');

            return $this->finishEnrollment($request, $user, 'totp');
        });
    }

    public function sendChannelChallenge(Request $request, MfaFactorManager $mfaFactors): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:email,sms'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $method = $validated['method'];

        if ($redirect = $this->requireRecentMfa($request, $user)) {
            return $redirect;
        }

        if (MFA::isEnabled($user, $method)) {
            return back()->with('error', ucfirst($method).' verification is already enabled.');
        }

        if ($method === 'email' && $user->email_verified_at === null) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Verify your email address before adding email verification.');
        }

        if ($method === 'sms' && blank($user->phone)) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Add a phone number to your profile before setting up SMS verification.');
        }

        try {
            if (! MFA::issueChallenge($user, $method)) {
                throw new \RuntimeException('The MFA delivery channel is unavailable.');
            }
        } catch (Throwable $exception) {
            $mfaFactors->deleteChallenges($user, $method);
            Log::warning('MFA enrollment code could not be sent.', [
                'user_id' => $user->getKey(),
                'method' => $method,
                'exception' => $exception::class,
            ]);

            return back()->with('error', 'We could not send the verification code. Check your delivery settings and try again.');
        }

        $request->session()->put('mfa.enrollment.method', $method);
        $destination = $method === 'email' ? $user->email : $user->phone;

        return redirect()->route('profile.security.index')
            ->with('status', 'A verification code was sent to '.$this->maskDestination($method, $destination).'.');
    }

    public function confirmChannelChallenge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:email,sms'],
            'code' => ['required', 'digits:6'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $method = $validated['method'];

        if ($request->session()->get('mfa.enrollment.method') !== $method) {
            return back()->withErrors([
                'code' => 'Request a new verification code before confirming this method.',
            ]);
        }

        if ($redirect = $this->requireRecentMfa($request, $user)) {
            return $redirect;
        }

        if ($method === 'email' && $user->email_verified_at === null) {
            return back()->with('error', 'Verify your email address before adding email verification.');
        }

        if ($method === 'sms' && blank($user->phone)) {
            return back()->with('error', 'Add a phone number to your profile before setting up SMS verification.');
        }

        return DB::transaction(function () use ($request, $user, $method, $validated): RedirectResponse {
            if (! MFA::verifyChallenge($user, $method, $validated['code'])) {
                return back()->withErrors([
                    'code' => 'The verification code is invalid or expired. Request a new code and try again.',
                ]);
            }

            if ($method === 'sms') {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            $request->session()->forget('mfa.enrollment.method');

            return $this->finishEnrollment($request, $user, $method);
        });
    }

    public function removeMethod(Request $request, string $method, MfaFactorManager $mfaFactors): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);
        /** @var User $user */
        $user = $request->user();

        if (! in_array($method, ['totp', 'email', 'sms'], true) || ! MFA::isEnabled($user, $method)) {
            return back()->with('error', 'That verification method is not enabled.');
        }

        if (count($mfaFactors->enabledMethods($user)) === 1 && $this->isMfaRequired($user)) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Your organization requires two-factor authentication. Add another method before removing this one.');
        }

        DB::transaction(function () use ($mfaFactors, $user, $method): void {
            $mfaFactors->removeMethod($user, $method);

            if ($mfaFactors->enabledMethods($user) === []) {
                $user->forceFill(['mfa_enrollment_required_at' => null])->save();
            }

            AuditLog::record('mfa.method_removed', $user, ['method' => $method], $user);
        });

        return redirect()->route('profile.security.index')
            ->with('status', ucfirst($method).' verification was removed.');
    }

    private function finishEnrollment(
        Request $request,
        User $user,
        string $method,
    ): RedirectResponse {
        $user->forceFill(['mfa_enrollment_required_at' => null])->save();
        MfaSession::markVerified($request);
        AuditLog::record('mfa.factor.enrolled', $user, ['method' => $method], $user);

        $recoveryCodes = MFA::getRemainingRecoveryCodesCount($user) === 0
            ? MFA::generateRecoveryCodes($user)
            : [];
        $response = redirect()->route('profile.security.index')
            ->with('status', ucfirst($method).' verification was enabled successfully.');

        if ($recoveryCodes !== []) {
            return $response->with('recovery_codes', $recoveryCodes);
        }

        return $response;
    }

    private function requireRecentMfa(Request $request, User $user): ?RedirectResponse
    {
        if (! $user->hasEnabledTwoFactorAuthentication()
            || MfaSession::isRecent($request, (int) Policy::get(
                'security.step_up_minutes',
                config('security.step_up_minutes', 10)
            ))) {
            return null;
        }

        $request->session()->put('mfa.step_up_return', route('profile.security.index'));

        return redirect()->route('mfa.step-up')
            ->with('error', 'Confirm your identity before changing verification methods.');
    }

    private function isMfaRequired(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return Policy::get('security.platform_admin_mfa', true)
                && config('security.platform_admin_mfa_required', true);
        }

        return (bool) ($user->tenant?->settings['require_2fa'] ?? false);
    }

    private function maskDestination(string $method, string $destination): string
    {
        if ($method === 'email') {
            [$local, $domain] = array_pad(explode('@', $destination, 2), 2, '');

            return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
        }

        $normalized = SmsService::normalizePhoneNumber($destination) ?? $destination;

        return str_repeat('*', max(0, strlen($normalized) - 4)).substr($normalized, -4);
    }
}
