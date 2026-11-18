<?php

namespace App\Services;

/** RADIUS is opt-in and remains separately gated in production. */
final class RadiusReadiness
{
    public static function available(): bool
    {
        if (! (bool) config('radius.enabled', false)) {
            return false;
        }

        return ! app()->environment('production') || (bool) config('radius.production_enabled', false);
    }

    public static function requireAvailable(): void
    {
        if (! self::available()) {
            throw new RadiusUnavailableException('RADIUS access is temporarily unavailable. Contact the operator; please do not pay again.');
        }
    }
}
