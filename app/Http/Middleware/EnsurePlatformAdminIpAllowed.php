<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedRanges = PlatformSetting::get('security.admin_ip_allow', []);
        if (! is_array($allowedRanges) || $allowedRanges === []) {
            return $next($request);
        }

        $clientIp = $request->ip();
        if (is_string($clientIp)) {
            foreach ($allowedRanges as $range) {
                if (is_string($range) && IpUtils::checkIp($clientIp, trim($range))) {
                    return $next($request);
                }
            }
        }

        Log::warning('Blocked platform admin request from an IP outside the configured allow-list.', [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $clientIp,
        ]);

        abort(Response::HTTP_FORBIDDEN, 'This network is not allowed to access platform administration.');
    }
}
