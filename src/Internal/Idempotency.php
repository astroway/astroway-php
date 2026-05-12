<?php

declare(strict_types=1);

namespace Astroway\Internal;

/**
 * Idempotency-Key generation + policy. Mirrors the TypeScript / Python SDKs:
 * every credit-costing POST gets a fresh UUIDv4 by default, so a network-blip
 * retry never double-bills. Server-side dedup uses the header to short-circuit.
 *
 * Policy values for the `idempotency` constructor option:
 *   - 'auto'      — generate a UUIDv4 per POST (default).
 *   - 'off'       — never auto-generate; caller controls the header.
 *   - callable    — `fn(): string` returning a custom key (deterministic test
 *                   keys, ULIDs, etc).
 */
final class Idempotency
{
    /** Generate an RFC 4122 v4 UUID without pulling ramsey/uuid as a hard dep. */
    public static function generateKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @param string|callable|null $mode
     */
    public static function shouldAttach(string|callable|null $mode, string $method): bool
    {
        if ($mode === 'off') {
            return false;
        }

        return strtoupper($method) === 'POST';
    }

    /**
     * @param string|callable|null $mode
     *
     * @return callable(): string
     */
    public static function resolveGenerator(string|callable|null $mode): callable
    {
        if (is_callable($mode)) {
            return $mode;
        }

        return [self::class, 'generateKey'];
    }
}
