<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Concurrent;
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ConcurrentTest extends TestCase
{
    private function makeClient(MockHttpClient $http): Astroway
    {
        return new Astroway([
            'apiKey' => 'aw_test_x',
            'httpClient' => $http,
            'requestFactory' => new Psr17Factory(),
            'streamFactory' => new Psr17Factory(),
        ]);
    }

    public function testAllRunsTasksAndReturnsOrderedResults(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'a'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'b'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'c'])),
        ]);
        $aw = $this->makeClient($http);
        $tasks = [
            fn() => $aw->post('/chart', ['x' => 1]),
            fn() => $aw->post('/chart', ['x' => 2]),
            fn() => $aw->post('/chart', ['x' => 3]),
        ];
        $results = $aw->concurrent()->all($tasks);
        self::assertSame(['a', 'b', 'c'], $results);
        self::assertSame(3, count($http->requests()));
    }

    public function testAllPlacesErrorsAtTheirIndexInsteadOfThrowing(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'first'])),
            new Response(401, [], json_encode(['ok' => false, 'error' => ['code' => 'INVALID_API_KEY', 'message' => 'bad']])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'third'])),
        ]);
        $aw = $this->makeClient($http);
        $results = $aw->concurrent()->all([
            fn() => $aw->post('/chart', ['x' => 1]),
            fn() => $aw->post('/chart', ['x' => 2]),
            fn() => $aw->post('/chart', ['x' => 3]),
        ]);
        self::assertSame('first', $results[0]);
        self::assertInstanceOf(AuthenticationError::class, $results[1]);
        self::assertSame('third', $results[2]);
    }

    public function testAllOrFailThrowsOnFirstFailure(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'first'])),
            new Response(401, [], json_encode(['ok' => false, 'error' => ['code' => 'INVALID_API_KEY', 'message' => 'bad']])),
        ]);
        $aw = $this->makeClient($http);
        $this->expectException(AuthenticationError::class);
        $aw->concurrent()->allOrFail([
            fn() => $aw->post('/chart', ['x' => 1]),
            fn() => $aw->post('/chart', ['x' => 2]),
            fn() => $aw->post('/chart', ['x' => 3]), // never reached
        ]);
    }

    public function testMapPreservesKeys(): void
    {
        $http = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => 'A'])),
            new Response(200, [], json_encode(['ok' => true, 'data' => 'B'])),
        ]);
        $aw = $this->makeClient($http);
        $results = $aw->concurrent()->map([
            'alice' => fn() => $aw->post('/chart', ['x' => 1]),
            'bob'   => fn() => $aw->post('/chart', ['x' => 2]),
        ]);
        self::assertSame(['alice' => 'A', 'bob' => 'B'], $results);
    }

    public function testMaxConcurrencyValidation(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_x']);
        $this->expectException(\InvalidArgumentException::class);
        $aw->concurrent(maxConcurrency: 0);
    }

    public function testConcurrentExposesMaxConcurrency(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_x']);
        $c = $aw->concurrent(maxConcurrency: 25);
        self::assertSame(25, $c->maxConcurrency);
    }

    public function testEmptyTasksReturnsEmpty(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_x']);
        self::assertSame([], $aw->concurrent()->all([]));
        self::assertSame([], $aw->concurrent()->allOrFail([]));
        self::assertSame([], $aw->concurrent()->map([]));
    }

    public function testConcurrentInstanceReturnedFromAstroway(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_x']);
        self::assertInstanceOf(Concurrent::class, $aw->concurrent());
    }

    public function testApiErrorPlacedDirectlyInsteadOfWrappedThrough(): void
    {
        // Even when wrapped, AuthenticationError extends ApiError — we want
        // the original instance, not a fresh ApiError surrogate.
        $http = new MockHttpClient([
            new Response(401, [], json_encode(['ok' => false, 'error' => ['code' => 'INVALID_API_KEY', 'message' => 'bad']])),
        ]);
        $aw = $this->makeClient($http);
        $results = $aw->concurrent()->all([
            fn() => $aw->post('/chart', ['x' => 1]),
        ]);
        self::assertInstanceOf(ApiError::class, $results[0]);
    }
}
