<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRadiusPolicyService
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sourceIsAllowed($request)) {
            return $this->unauthorized();
        }

        $serviceSecret = config('radius_policy.service_secret');
        $appKey = config('app.key');
        $timestamp = (string) $request->header('X-Radius-Service-Timestamp', '');
        $nonce = (string) $request->header('X-Radius-Service-Nonce', '');
        $signature = (string) $request->header('X-Radius-Service-Signature', '');

        if (! is_string($serviceSecret)
            || strlen($serviceSecret) < 32
            || ! is_string($appKey)
            || hash_equals($appKey, $serviceSecret)
            || ! preg_match('/^\d{1,12}$/', $timestamp)
            || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || ! preg_match('/^[a-f0-9]{64}$/i', $signature)
            || $request->getQueryString() !== null
            || (app()->environment('production') && ! $request->isSecure())) {
            return $this->unauthorized();
        }

        $clockSkew = max(1, (int) config('radius_policy.clock_skew_seconds', 60));
        if (abs(time() - (int) $timestamp) > $clockSkew) {
            return $this->unauthorized();
        }

        // A named shared cache must be configured for production so nonce
        // replay protection is not accidentally limited to one PHP process.
        $nonceStore = config('radius_policy.nonce_cache_store');
        if (app()->environment('production')) {
            $nonceDriver = is_string($nonceStore) ? config("cache.stores.{$nonceStore}.driver") : null;
            if (! is_string($nonceStore)
                || $nonceStore === ''
                || ! in_array($nonceDriver, ['database', 'dynamodb', 'memcached', 'redis'], true)) {
                return $this->unauthorized();
            }
        }

        $canonical = implode("\n", [
            strtoupper($request->method()),
            $request->getPathInfo(),
            hash('sha256', $request->getContent()),
            $timestamp,
            $nonce,
        ]);
        $expected = hash_hmac('sha256', $canonical, $serviceSecret);

        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized();
        }

        try {
            $cache = Cache::store(is_string($nonceStore) && $nonceStore !== '' ? $nonceStore : null);
            $cacheKey = 'radius-policy:nonce:'.hash('sha256', $nonce);
            $nonceAccepted = $cache->add($cacheKey, true, now()->addSeconds($clockSkew * 2));
        } catch (\Throwable) {
            $nonceAccepted = false;
        }

        if (! $nonceAccepted) {
            return $this->unauthorized();
        }

        $response = $next($request);

        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sourceIsAllowed(Request $request): bool
    {
        $allowlist = config('radius_policy.allowed_source_cidrs');
        $production = app()->environment('production');

        if ($allowlist === null || $allowlist === []) {
            return ! $production;
        }

        if (! is_array($allowlist)) {
            return false;
        }

        // Use the TCP peer, not X-Forwarded-For. Symfony/Laravel may derive
        // client IPs from forwarding headers when trusted proxies are enabled.
        $peerAddress = $request->server->get('REMOTE_ADDR');
        if (! is_string($peerAddress) || filter_var($peerAddress, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($allowlist as $cidr) {
            if (! is_string($cidr) || ! $this->isValidSourceCidr($cidr)) {
                return false;
            }
        }

        return $allowlist !== [] && IpUtils::checkIp($peerAddress, $allowlist);
    }

    private function isValidSourceCidr(string $cidr): bool
    {
        if (substr_count($cidr, '/') !== 1) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $isIpv4 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ((! $isIpv4 && ! $isIpv6) || $prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $maximumPrefixLength = $isIpv4 ? 32 : 128;

        // /0 would make the allowlist equivalent to trusting every address.
        return $prefixLength > 0 && $prefixLength <= $maximumPrefixLength;
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Unauthorized.'], 401, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
