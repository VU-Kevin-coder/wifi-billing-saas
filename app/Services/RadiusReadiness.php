<?php

namespace App\Services;

/** Feature gating and live health are separate to keep the policy probe usable. */
final class RadiusReadiness
{
    /** This is the configuration gate used by the policy service itself. */
    public static function available(): bool
    {
        if (! (bool) config('radius.enabled', false)) {
            return false;
        }

        return ! app()->environment('production') || (bool) config('radius.production_enabled', false);
    }

    /** Live readiness is for onboarding and operator status, not policy requests. */
    public static function operational(): bool
    {
        return self::available()
            && app(SystemHealthService::class)->queueWorkerReady()
            && app(RadiusRuntimeHealthCheck::class)->available();
    }

    public static function requireAvailable(): void
    {
        if (! self::available()) {
            throw new RadiusUnavailableException('RADIUS access is temporarily unavailable. Contact the operator; please do not pay again.');
        }
    }
}
