<?php

namespace App\Http\Middleware;

use App\Services\Policy\Policy;
use App\Support\MfaSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive actions (payouts, balance adjustments,
 * payment credentials, impersonation). Usage: ->middleware('mfa.recent')
 * or ->middleware('mfa.recent:5') for a custom window in minutes.
 */
class RequireRecentMfa
{
    public function handle(Request $request, Closure $next, ?string $minutes = null): Response
    {
        $user = $request->user();

        $stepUpEnabled = Policy::get('security.step_up_enabled', true)
            && config('security.step_up_enabled', true);

        if (! $user || ! $stepUpEnabled) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('profile.security.index')
                ->with('error', 'Enable Two-Factor Authentication to perform this action.');
        }

        $window = $minutes !== null ? (int) $minutes : (int) Policy::get('security.step_up_minutes', config('security.step_up_minutes', 10));

        if (MfaSession::isRecent($request, $window)) {
            return $next($request);
        }

        // Remember where to send the user back to. A blocked POST cannot be
        // replayed, so the user returns to the page they came from and repeats it.
        $request->session()->put(
            'mfa.step_up_return',
            $request->isMethod('GET') ? $request->fullUrl() : url()->previous()
        );

        return redirect()->route('mfa.step-up')
            ->with('error', 'Please confirm your identity to continue.');
    }
}
