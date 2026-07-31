<?php
/**
 * HayabusaPress - Display date/time formatter (admin-configurable via .env).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Formats UTC datetime strings (as stored in the DB) into the site's configured
 * display format, converting to APP_TIMEZONE.
 *
 * Configuration (.env):
 *   DATE_FORMAT     - PHP date() syntax for date-only contexts   (default "Y-m-d")
 *   DATETIME_FORMAT - PHP date() syntax for date+time contexts   (default "Y-m-d H:i")
 *   APP_TIMEZONE    - display timezone; the DB always stores UTC (default "UTC")
 *
 * Values are read once and memoized for the request. NULL/empty inputs yield "".
 */
class Format
{
    private const DEFAULT_DATE     = 'Y-m-d';
    private const DEFAULT_DATETIME = 'Y-m-d H:i';
    private const DEFAULT_MONTH    = 'Y-m';

    private static ?string $dateFmt     = null;
    private static ?string $datetimeFmt = null;
    private static ?string $monthFmt    = null;
    private static ?string $tz          = null;

    /** Format a stored UTC datetime as a date-only string. */
    public static function date(?string $utc): string
    {
        if (self::$dateFmt === null) {
            self::$dateFmt = self::envFormat('DATE_FORMAT', self::DEFAULT_DATE);
        }
        $dt = self::toLocal($utc);
        return $dt ? $dt->format(self::$dateFmt) : '';
    }

    /** Format a stored UTC datetime as a date+time string. */
    public static function datetime(?string $utc): string
    {
        if (self::$datetimeFmt === null) {
            self::$datetimeFmt = self::envFormat('DATETIME_FORMAT', self::DEFAULT_DATETIME);
        }
        $dt = self::toLocal($utc);
        return $dt ? $dt->format(self::$datetimeFmt) : '';
    }

    /**
     * Format a "YYYY-MM" archive month label (e.g. for the archive widget / page heading).
     * No timezone conversion: the label is rendered literally from the year-month string so
     * it always matches the URL slug and the DB-side month grouping. Unknown input is returned as-is.
     */
    public static function yearMonth(?string $ym): string
    {
        if (self::$monthFmt === null) {
            self::$monthFmt = self::envFormat('ARCHIVE_MONTH_FORMAT', self::DEFAULT_MONTH);
        }
        $ym = is_string($ym) ? trim($ym) : '';
        if (!preg_match('/^(\d{4})-(\d{2})/', $ym, $m)) {
            return $ym;
        }
        try {
            $dt = new DateTimeImmutable($m[1] . '-' . $m[2] . '-01 00:00:00');
            return $dt->format(self::$monthFmt);
        } catch (\Throwable) {
            return $ym;
        }
    }

    /** Machine-readable ISO 8601 (for <time datetime="..."> / schema.org). */
    public static function iso(?string $utc): string
    {
        $dt = self::toLocal($utc);
        return $dt ? $dt->format(DateTimeInterface::ATOM) : '';
    }

    /** Convert a stored UTC datetime string into a DateTimeImmutable in APP_TIMEZONE. */
    private static function toLocal(?string $utc): ?DateTimeImmutable
    {
        $utc = is_string($utc) ? trim($utc) : '';
        // Empty / NULL-like / zero datetimes are treated as "no value".
        if ($utc === '' || str_starts_with($utc, '0000')) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone(self::tz()));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function tz(): string
    {
        if (self::$tz === null) {
            $t = class_exists('Env') ? (string)Env::get('APP_TIMEZONE', 'UTC') : 'UTC';
            self::$tz = $t !== '' ? $t : 'UTC';
        }
        return self::$tz;
    }

    private static function envFormat(string $key, string $default): string
    {
        $v = class_exists('Env') ? (string)Env::get($key, $default) : $default;
        return $v !== '' ? $v : $default;
    }
}
