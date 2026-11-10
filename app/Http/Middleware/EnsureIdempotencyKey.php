<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Idempotency-Key') ?? $request->request->get('_idempotency_key');
        if (! is_string($key) || ! preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $key)) {
            return $this->reject($request, 'A valid idempotency key is required.', 422);
        }

        $request->request->remove('_idempotency_key');

        $route = $request->route();
        $routeParameters = array_map(
            static fn ($value): string => $value instanceof UrlRoutable
                ? (string) $value->getRouteKey()
                : (is_scalar($value) ? (string) $value : get_debug_type($value)),
            $route?->parameters() ?? [],
        );
        $scope = implode('|', [
            $request->method(),
            $route?->getName() ?? $request->path(),
            implode('/', $routeParameters),
            (string) ($request->user()?->getAuthIdentifier() ?? 'guest'),
            (string) app(TenantContext::class)->getTenantId(),
            $request->hasSession() ? $request->session()->getId() : $request->ip(),
        ]);
        $cacheKey = 'idempotency:'.hash('sha256', $scope.'|'.hash('sha256', $key));

        if (! Cache::add($cacheKey, true, now()->addMinutes(5))) {
            return $this->reject($request, 'This request has already been submitted. Refresh before trying again.', 409);
        }

        return $next($request);
    }

    private function reject(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()
            ->withErrors(['idempotency_key' => $message])
            ->with('error', $message);
    }
}
