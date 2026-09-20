<?php

declare(strict_types=1);

namespace Astroway\Dto;

/**
 * Shared validation for the `timezone` field every birth-shaped DTO accepts.
 *
 * The API takes an IANA zone name ('Europe/Kyiv') or 'auto', and resolves it
 * into the offset in force at that local date. Three shapes come back 400, so
 * they are caught here rather than over the network.
 */
final class Timezone
{
    /** '+03:00', '-5' and 'UTC+2' are offsets, not zone names. */
    private const OFFSET_LIKE = '/^(?:UTC|GMT)?\s*[+-]?\d{1,2}(?::\d{2})?$/i';

    public static function assertValid(?string $timezone): void
    {
        if ($timezone === null) {
            return;
        }
        if (trim($timezone) === '') {
            throw new \InvalidArgumentException(
                "timezone must be a zone name or 'auto'; omit it rather than sending an empty string"
            );
        }
        if (preg_match(self::OFFSET_LIKE, $timezone)) {
            throw new \InvalidArgumentException(
                "timezone takes a zone name, not an offset; use timezoneOffset for '{$timezone}'"
            );
        }
    }
}
