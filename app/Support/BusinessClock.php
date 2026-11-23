<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Single source of truth for "now" and business-day boundaries.
 *
 * Timestamps are persisted in UTC; business dates (today's sales, overdue
 * installments, report ranges) are evaluated in the configured business
 * timezone so a 00:30 Dhaka sale never lands in the previous UTC day.
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('erp.timezone', 'Asia/Dhaka');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function utcNow(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function toUtc(CarbonInterface|string|null $moment): CarbonImmutable
    {
        if ($moment === null) {
            return self::utcNow();
        }

        if (is_string($moment)) {
            $moment = CarbonImmutable::parse($moment, self::timezone());
        }

        return CarbonImmutable::instance($moment)->setTimezone('UTC');
    }

    public static function toBusiness(CarbonInterface|string|null $moment): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        if (is_string($moment)) {
            $moment = CarbonImmutable::parse($moment, 'UTC');
        }

        return CarbonImmutable::instance($moment)->setTimezone(self::timezone());
    }

    public static function businessDayStartUtc(?CarbonInterface $date = null): CarbonImmutable
    {
        $business = $date
            ? CarbonImmutable::instance($date)->setTimezone(self::timezone())
            : self::now();

        return $business->startOfDay()->setTimezone('UTC');
    }

    public static function businessDayEndUtc(?CarbonInterface $date = null): CarbonImmutable
    {
        $business = $date
            ? CarbonImmutable::instance($date)->setTimezone(self::timezone())
            : self::now();

        return $business->endOfDay()->setTimezone('UTC');
    }
}
