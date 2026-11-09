<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating a staff member must take effect on their very next request,
 * even if they are already signed in somewhere.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->deactivated_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'This account has been deactivated.');
            }

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
        }

        return $next($request);
    }
}
