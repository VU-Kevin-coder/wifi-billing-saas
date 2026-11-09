<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A package's data allowance is free text ("Unlimited", "5GB", "500 MB").
 * The router needs a byte count, so parse it strictly: a limit that cannot be
 * understood must be rejected when the package is saved, not guessed later.
 * Units are binary (1 GB = 1024^3 bytes), the way RouterOS counts.
 */
class DataLimit
{
    private const UNITS = ['KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4];

    /** True for blank or "unlimited"-style text. */
    public static function isUnlimited(?string $value): bool
    {
        $value = strtolower(trim((string) $value));

        return $value === '' || in_array($value, ['unlimited', 'unlimited data', 'no limit', 'none', 'n/a'], true);
    }

    /**
     * @return int|null bytes, or null for unlimited
     *
     * @throws InvalidArgumentException when the text is neither unlimited nor "<number> <KB|MB|GB|TB>"
     */
    public static function bytes(?string $value): ?int
    {
        if (self::isUnlimited($value)) {
            return null;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(kb|mb|gb|tb)(?:ytes?)?\s*$/i', (string) $value, $m)) {
            throw new InvalidArgumentException("Unrecognised data limit '{$value}'.");
        }

        $bytes = (float) $m[1] * (1024 ** self::UNITS[strtoupper($m[2])]);

        if ($bytes < 1) {
            throw new InvalidArgumentException('A data limit must be greater than zero.');
        }

        return (int) round($bytes);
    }

    public static function isValid(?string $value): bool
    {
        try {
            self::bytes($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
