<?php

namespace App\Support;

use Illuminate\Http\Request;

class MfaSession
{
    /**
     * Record a successful two-factor verification in the session.
     */
    public static function markVerified(Request $request): void
    {
        $request->session()->put('mfa.verified', true);
        $request->session()->put('mfa.verified_at', now()->getTimestamp());
    }

    /**
     * Whether a two-factor verification happened within the last $minutes.
     */
    public static function isRecent(Request $request, int $minutes): bool
    {
        $verifiedAt = $request->session()->get('mfa.verified_at');

        return $request->session()->get('mfa.verified') === true
            && is_int($verifiedAt)
            && $verifiedAt >= now()->subMinutes(max(1, $minutes))->getTimestamp();
    }
}
