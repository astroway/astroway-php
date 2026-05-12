<?php

declare(strict_types=1);

namespace Astroway\Internal;

/**
 * Decides whether a given path is safe to cache by default.
 *
 * Pure functions of `(date, time, lat, lon, tz)` are deterministic — caching
 * them saves credits + makes dev loops instant. Time-sensitive endpoints
 * (transits, daily horoscope, AI) must not be cached without explicit opt-in,
 * since `aw->charts()->transits()` for "now" returns different results per
 * minute.
 *
 * The default allow/deny lists are tuned for the AstroWay v1 surface; users
 * always have the final word via the per-call `cache` option:
 *
 *   $aw->charts()->natal($body, ['cache' => true])     // force cache
 *   $aw->charts()->natal($body, ['cache' => false])    // force-skip cache
 */
final class CachePolicy
{
    /**
     * Path prefixes that are pure functions and safe to cache by default.
     * Any path starting with these strings (after the version prefix is
     * stripped) is treated as deterministic.
     */
    public const DETERMINISTIC_PREFIXES = [
        '/chart',
        '/synastry',
        '/composite',
        '/midpoints',
        '/aspects',
        '/houses',
        '/planets',
        '/vedic/',
        '/numerology/',
        '/tarot/',
        '/hd/',
        '/human-design/',
        '/dasha/',
    ];

    /**
     * Path prefixes that are time-sensitive (or LLM-driven) and must NOT be
     * cached unless the caller explicitly opts in.
     */
    public const NON_DETERMINISTIC_PREFIXES = [
        '/transits',
        '/horoscope',
        '/interpret',
        '/ai/',
        '/mcp/',
        '/stream/',
        '/now',
        '/today',
    ];

    public static function isDeterministic(string $path): bool
    {
        $normalised = self::stripVersionPrefix($path);
        foreach (self::NON_DETERMINISTIC_PREFIXES as $prefix) {
            if (str_starts_with($normalised, $prefix)) {
                return false;
            }
        }
        foreach (self::DETERMINISTIC_PREFIXES as $prefix) {
            if (str_starts_with($normalised, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `/v1/chart` and `/chart` should map to the same policy entry.
     */
    private static function stripVersionPrefix(string $path): string
    {
        if (preg_match('#^/v\d+(/.*)?$#', $path, $m) === 1) {
            return $m[1] ?? '/';
        }

        return $path;
    }
}
