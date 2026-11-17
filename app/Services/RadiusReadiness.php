<?php

namespace App\Services;

/** Disabled until the policy service can authenticate the actual NAS. */
final class RadiusReadiness
{
    public static function available(): bool { return false; }
    public static function requireAvailable(): void
    {
        if (! self::available()) {
            throw new RadiusUnavailableException('RADIUS access is temporarily unavailable. Contact the operator; please do not pay again.');
        }
    }
}
