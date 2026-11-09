<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;

/**
 * "Today", "this week" and "this month" as the operator experiences them.
 *
 * Timestamps are stored in UTC (config app.timezone). Comparing them with
 * UTC day boundaries shifts every report by hours for East Africa, so
 * the boundaries are computed in the configured platform timezone and then
 * converted back to the storage timezone for the query.
 */
class ReportingTime
{
    public static function timezone(): string
    {
        $configured = (string) PlatformSetting::get('timezone', 'Africa/Kampala');

        return in_array($configured, \DateTimeZone::listIdentifiers(), true)
            ? $configured
            : (string) config('app.timezone', 'UTC');
    }

    /** The current moment, expressed in the reporting timezone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** @return array{0: Carbon, 1: Carbon} start and end of the local day, in storage time */
    public static function dayRange(?Carbon $localMoment = null): array
    {
        $moment = ($localMoment ?? self::now())->copy()->setTimezone(self::timezone());

        return [self::toStorage($moment->copy()->startOfDay()), self::toStorage($moment->copy()->endOfDay())];
    }

    /** @return array{0: Carbon, 1: Carbon} Monday to Sunday containing now */
    public static function weekRange(): array
    {
        $now = self::now();

        return [self::toStorage($now->copy()->startOfWeek()), self::toStorage($now->copy()->endOfWeek())];
    }

    /** @return array{0: Carbon, 1: Carbon} first to last moment of the current local month */
    public static function monthRange(): array
    {
        $now = self::now();

        return [self::toStorage($now->copy()->startOfMonth()), self::toStorage($now->copy()->endOfMonth())];
    }

    private static function toStorage(Carbon $localMoment): Carbon
    {
        return $localMoment->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
