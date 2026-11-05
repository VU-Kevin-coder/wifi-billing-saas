<?php

namespace App\Http\Middleware;

use App\Models\SiteAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSiteAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && $token !== '', 401);

        $agent = SiteAgent::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        abort_unless($agent, 401);
        abort_if(! hash_equals($agent->token_hash, hash('sha256', $token)), 401);

        $request->attributes->set('site_agent', $agent);

        return $next($request);
    }
}
