<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Throwable;

/**
 * The short link a customer follows to see their plan and renew it.
 * The token is random (12 chars, ~10^18 combinations) and opens only that one session.
 */
class RenewLink
{
    public function tokenFor(Session $session): string
    {
        $existing = Session::withoutTenancy()->whereKey($session->id)->value('renew_token');
        if ($existing) {
            return $existing;
        }

        do {
            $token = Str::lower(Str::random(12));
        } while (Session::withoutTenancy()->where('renew_token', $token)->exists());

        // whereNull: if two requests race, the first token wins and both return it.
        Session::withoutTenancy()->whereKey($session->id)->whereNull('renew_token')->update(['renew_token' => $token]);

        return (string) Session::withoutTenancy()->whereKey($session->id)->value('renew_token');
    }

    public function urlFor(Tenant $tenant, string $token): string
    {
        try {
            return route('portal.plan.show', ['tenant' => $tenant->slug, 'token' => $token]);
        } catch (Throwable) {
            return rtrim((string) config('app.url'), '/').'/portal/r/'.$token;
        }
    }
}
