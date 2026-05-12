<?php

declare(strict_types=1);

namespace Astroway\Internal;

/**
 * Builds deterministic cache keys for request bodies.
 *
 * Two requests with the same path and same logical body must produce the same
 * key, even if the user sent fields in a different order or with different
 * float formatting (`50.45` vs `50.450`). The key is a SHA-256 of canonical JSON.
 *
 * The key is namespaced (`astroway:v1:<hash>`) so:
 *   - SDK upgrades that change the canonical form bump the prefix and invalidate
 *     stale entries automatically
 *   - users sharing a cache backend (redis) between multiple SDKs see no collisions
 */
final class CacheKey
{
    /**
     * Prefix uses underscores rather than colons because PSR-16 reserves
     * `{}()/\@:` from cache keys (some adapters — Symfony, Redis tag-aware —
     * throw on them).
     */
    public const PREFIX = 'astroway_v1_';

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     */
    public static function build(string $method, string $path, $body): string
    {
        $canonical = self::canonicalise([
            'm' => strtoupper($method),
            'p' => $path,
            'b' => $body,
        ]);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('CacheKey: failed to encode canonical body — '.json_last_error_msg());
        }

        return self::PREFIX.hash('sha256', $json);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function canonicalise($value)
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if ($isList) {
                return array_map([self::class, 'canonicalise'], $value);
            }
            ksort($value);
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::canonicalise($v);
            }

            return $out;
        }

        return $value;
    }
}
