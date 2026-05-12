<?php

declare(strict_types=1);

namespace Astroway\Testing;

use Astroway\Astroway;
use Astroway\Errors\ApiError;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Drop-in test double for `Astroway`. Records every call, returns scripted
 * fixtures with zero HTTP traffic. Inherits the full namespace surface
 * (`$mock->charts()->natal(...)`, all 100+ services).
 *
 * Example:
 *
 *     use Astroway\Testing\MockAstroway;
 *
 *     $mock = new MockAstroway();
 *     $mock->respond('POST', '/chart', ['angles' => ['asc' => 'Aries']]);
 *     $r = $mock->charts()->natal($body);
 *     $this->assertSame('Aries', $r['angles']['asc']);
 *     $this->assertCount(1, $mock->calls);
 *
 * Throwable fixtures are thrown by the mock — pair with `mockApiError()` to
 * assert your retry / error-handling code paths.
 */
final class MockAstroway extends Astroway
{
    /**
     * Recorded calls, in order. Each entry: ['method', 'path', 'body', 'headers', 'resolved'].
     * `resolved` is the value/throwable produced by the matched fixture.
     *
     * @var list<array{method: string, path: string, body: mixed, headers: array<string,mixed>, resolved: mixed}>
     */
    public array $calls = [];

    /** @var array<string, list<mixed>> */
    private array $fixtures = [];

    /**
     * @param array<string, mixed> $options Optional Astroway options. apiKey defaults to 'mock_test_key'.
     */
    public function __construct(array $options = [])
    {
        parent::__construct(array_merge([
            'apiKey' => 'mock_test_key',
            'httpClient' => new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new \LogicException(
                        'MockAstroway: HTTP layer reached unexpectedly. '
                        .'This means the request() override was bypassed — please file a bug.',
                    );
                }
            },
        ], $options));
    }

    /**
     * Register a fixture. Multiple fixtures for the same (method, path) pair are
     * served in order; the last one repeats indefinitely once its index is reached.
     *
     * Fixtures may be:
     * - A plain value (array, string, scalar, null) — returned as-is.
     * - A `\Throwable` — thrown when the route is hit.
     * - A `callable(array $ctx): mixed` where `$ctx = ['method', 'path', 'body', 'callIndex']`.
     */
    public function respond(string $method, string $path, mixed $fixture): self
    {
        $key = strtoupper($method).' '.$path;
        if (!isset($this->fixtures[$key])) {
            $this->fixtures[$key] = [];
        }
        $this->fixtures[$key][] = $fixture;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        $key = strtoupper($method).' '.$path;
        $body = $options['json'] ?? null;
        /** @var array<string, mixed> $headers */
        $headers = $options['headers'] ?? [];

        $callIndex = 0;
        foreach ($this->calls as $c) {
            if (strtoupper($c['method']) === strtoupper($method) && $c['path'] === $path) {
                $callIndex++;
            }
        }

        if (!isset($this->fixtures[$key]) || $this->fixtures[$key] === []) {
            throw new ApiError(sprintf(
                "MockAstroway: no fixture for %s %s. "
                ."Call \$mock->respond('%s', '%s', \$value) before invoking this endpoint.",
                strtoupper($method), $path, strtoupper($method), $path,
            ));
        }

        $fixtures = $this->fixtures[$key];
        $fixture = $fixtures[min($callIndex, count($fixtures) - 1)];
        $resolved = is_callable($fixture) && !is_string($fixture) && !is_array($fixture)
            ? $fixture(['method' => $method, 'path' => $path, 'body' => $body, 'callIndex' => $callIndex])
            : $fixture;

        $this->calls[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'headers' => $headers,
            'resolved' => $resolved,
        ];

        if ($resolved instanceof \Throwable) {
            throw $resolved;
        }

        return $resolved;
    }

    /**
     * @return list<array{method: string, path: string, body: mixed, headers: array<string,mixed>, resolved: mixed}>
     */
    public function callsFor(string $path, ?string $method = null): array
    {
        $up = $method !== null ? strtoupper($method) : null;

        return array_values(array_filter($this->calls, static fn (array $c): bool =>
            $c['path'] === $path && ($up === null || strtoupper($c['method']) === $up)
        ));
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** Reset all calls AND fixtures (use in `setUp()` / `setUpBeforeClass()`). */
    public function reset(): self
    {
        $this->calls = [];
        $this->fixtures = [];

        return $this;
    }
}
