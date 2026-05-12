<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Internal\CacheKey;
use Astroway\Internal\CachePolicy;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class CacheTest extends TestCase
{
    private function makeCache(): CacheInterface
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    private function makeClient(MockHttpClient $http, ?CacheInterface $cache): Astroway
    {
        $opts = [
            'apiKey' => 'aw_test_x',
            'httpClient' => $http,
            'requestFactory' => new \Nyholm\Psr7\Factory\Psr17Factory(),
            'streamFactory' => new \Nyholm\Psr7\Factory\Psr17Factory(),
        ];
        if ($cache !== null) {
            $opts['cache'] = $cache;
        }

        return new Astroway($opts);
    }

    // ─── CacheKey ──────────────────────────────────────────────

    public function testCacheKeyIsStableAcrossKeyOrder(): void
    {
        $a = CacheKey::build('POST', '/chart', ['date' => '1990-07-14', 'lat' => 50.45]);
        $b = CacheKey::build('POST', '/chart', ['lat' => 50.45, 'date' => '1990-07-14']);
        self::assertSame($a, $b);
    }

    public function testCacheKeyDiffersByMethod(): void
    {
        self::assertNotSame(
            CacheKey::build('POST', '/chart', ['x' => 1]),
            CacheKey::build('GET', '/chart', ['x' => 1]),
        );
    }

    public function testCacheKeyDiffersByPath(): void
    {
        self::assertNotSame(
            CacheKey::build('POST', '/chart', ['x' => 1]),
            CacheKey::build('POST', '/synastry', ['x' => 1]),
        );
    }

    public function testCacheKeyHasNamespacePrefix(): void
    {
        $key = CacheKey::build('POST', '/chart', ['x' => 1]);
        self::assertStringStartsWith('astroway_v1_', $key);
    }

    public function testCacheKeyHandlesNestedArrays(): void
    {
        $a = CacheKey::build('POST', '/synastry', [
            'a' => ['date' => '1990', 'lat' => 50],
            'b' => ['date' => '1991', 'lat' => 51],
        ]);
        $b = CacheKey::build('POST', '/synastry', [
            'b' => ['lat' => 51, 'date' => '1991'],
            'a' => ['lat' => 50, 'date' => '1990'],
        ]);
        self::assertSame($a, $b);
    }

    public function testCacheKeyPreservesListOrder(): void
    {
        // Lists are positional — [1,2,3] != [3,2,1].
        $a = CacheKey::build('POST', '/x', ['items' => [1, 2, 3]]);
        $b = CacheKey::build('POST', '/x', ['items' => [3, 2, 1]]);
        self::assertNotSame($a, $b);
    }

    // ─── CachePolicy ───────────────────────────────────────────

    public function testCachePolicyAllowsDeterministicEndpoints(): void
    {
        self::assertTrue(CachePolicy::isDeterministic('/chart'));
        self::assertTrue(CachePolicy::isDeterministic('/synastry'));
        self::assertTrue(CachePolicy::isDeterministic('/v1/chart'));
        self::assertTrue(CachePolicy::isDeterministic('/vedic/dasha'));
        self::assertTrue(CachePolicy::isDeterministic('/numerology/pythagorean'));
    }

    public function testCachePolicyDeniesNonDeterministicEndpoints(): void
    {
        self::assertFalse(CachePolicy::isDeterministic('/transits'));
        self::assertFalse(CachePolicy::isDeterministic('/horoscope/daily'));
        self::assertFalse(CachePolicy::isDeterministic('/interpret/natal'));
        self::assertFalse(CachePolicy::isDeterministic('/v1/transits'));
        self::assertFalse(CachePolicy::isDeterministic('/now'));
    }

    public function testCachePolicyUnknownEndpointDeniedByDefault(): void
    {
        self::assertFalse(CachePolicy::isDeterministic('/somethingNew'));
    }

    // ─── End-to-end ────────────────────────────────────────────

    public function testCacheHitSkipsHttpForDeterministicEndpoint(): void
    {
        $cache = $this->makeCache();
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['asc' => 'Aries']])),
            // No second response scripted — second call must hit cache.
        ]);
        $aw = $this->makeClient($http, $cache);

        $first = $aw->post('/chart', ['date' => '1990-07-14']);
        $second = $aw->post('/chart', ['date' => '1990-07-14']);

        self::assertSame($first, $second);
        self::assertSame(1, count($http->requests()));
    }

    public function testCacheKeyOrderIndependent(): void
    {
        $cache = $this->makeCache();
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'cached'])),
        ]);
        $aw = $this->makeClient($http, $cache);

        $aw->post('/chart', ['date' => '1990', 'lat' => 50]);
        $second = $aw->post('/chart', ['lat' => 50, 'date' => '1990']);

        self::assertSame('cached', $second);
        self::assertSame(1, count($http->requests()));
    }

    public function testCacheSkippedForNonDeterministicEndpoint(): void
    {
        $cache = $this->makeCache();
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'a'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'b'])),
        ]);
        $aw = $this->makeClient($http, $cache);

        $first = $aw->post('/transits', ['date' => 'now']);
        $second = $aw->post('/transits', ['date' => 'now']);

        self::assertSame('a', $first);
        self::assertSame('b', $second);
        self::assertSame(2, count($http->requests()));
    }

    public function testPerCallCacheTrueForcesCacheOnNonDeterministicEndpoint(): void
    {
        $cache = $this->makeCache();
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'forced'])),
        ]);
        $aw = $this->makeClient($http, $cache);

        $first = $aw->request('POST', '/transits', ['json' => ['x' => 1], 'cache' => true]);
        $second = $aw->request('POST', '/transits', ['json' => ['x' => 1], 'cache' => true]);

        self::assertSame('forced', $second);
        self::assertSame(1, count($http->requests()));
    }

    public function testPerCallCacheFalseSkipsCacheOnDeterministicEndpoint(): void
    {
        $cache = $this->makeCache();
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'fresh-1'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'fresh-2'])),
        ]);
        $aw = $this->makeClient($http, $cache);

        $first = $aw->request('POST', '/chart', ['json' => ['x' => 1], 'cache' => false]);
        $second = $aw->request('POST', '/chart', ['json' => ['x' => 1], 'cache' => false]);

        self::assertSame('fresh-1', $first);
        self::assertSame('fresh-2', $second);
        self::assertSame(2, count($http->requests()));
    }

    public function testNoCacheBackendBehavesLikeBeta1(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => '1'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => '2'])),
        ]);
        $aw = $this->makeClient($http, null);

        $aw->post('/chart', ['date' => '1990']);
        $aw->post('/chart', ['date' => '1990']);

        // No cache configured → both calls hit HTTP.
        self::assertSame(2, count($http->requests()));
    }
}
