<?php
declare(strict_types=1);

/**
 * Dates as the Next.js back office handles them. Prisma keeps them in UTC,
 * to the millisecond ("2026-11-21 10:04:05.123"); JavaScript gives them as a
 * number of milliseconds (getTime) or as ISO text (toISOString).
 */
final class Dates
{
    /** A stored date → getTime(). */
    public static function ms(?string $utc): int
    {
        if ($utc === null || $utc === '') {
            return 0;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', str_contains($utc, '.') ? $utc : "$utc.0", new DateTimeZone('UTC'))
            ?: new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return (int) $d->format('U') * 1000 + (int) $d->format('v');
    }

    /** A stored date → toISOString(): "2026-11-21T10:04:05.123Z". */
    public static function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $ms = self::ms($utc);
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    /** Now, to the millisecond, as Prisma writes it. */
    public static function now(int $offsetMs = 0): string
    {
        $ms = (int) floor(microtime(true) * 1000) + $offsetMs;
        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)) . sprintf('.%03d', $ms % 1000);
    }

    /** Milliseconds → a stored date. */
    public static function fromMs(int $ms): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)) . sprintf('.%03d', $ms % 1000);
    }
}
