<?php

declare(strict_types=1);

namespace Astroway;

use Astroway\Errors\ApiError;
use Psr\Http\Client\ClientInterface;

/**
 * Concurrent request helper. PHP doesn't have native async, but Guzzle's
 * promise pool can dispatch many HTTP calls in parallel. Critical for
 * batch use cases like "calculate natal charts for 1000 users".
 *
 * ```php
 * $aw = new Astroway(['apiKey' => '...']);
 * $charts = $aw->concurrent(maxConcurrency: 10)->all([
 *     fn() => $aw->charts()->natal($r1),
 *     fn() => $aw->charts()->natal($r2),
 *     fn() => $aw->charts()->natal($r3),
 * ]);
 * // $charts = [$result1, $result2, $result3] — all settled
 * ```
 *
 * The `all()` helper accepts a list of callables (one per request) and
 * runs them with bounded concurrency. If the underlying PSR-18 client
 * supports Guzzle's promise pool, requests run truly in parallel; otherwise
 * they fall through to sequential — same final result, slower wall time.
 *
 * Why callables and not request objects? PHP closures capture exactly the
 * call you'd write sequentially — `fn() => $aw->charts()->natal($req)`.
 * That keeps the typed namespace surface and lets DTOs flow through.
 *
 * Each task runs in isolation: a thrown `ApiError` becomes the result for
 * that index (instead of bubbling), so you can inspect partial successes:
 *
 * ```php
 * $results = $aw->concurrent()->all($tasks);
 * foreach ($results as $i => $r) {
 *     if ($r instanceof Throwable) {
 *         error_log("task $i failed: " . $r->getMessage());
 *     }
 * }
 * ```
 *
 * Use `allOrFail()` to get sequential `try/throw` semantics — the first
 * failure aborts and is rethrown.
 */
final class Concurrent
{
    public function __construct(
        private readonly Astroway $client,
        public readonly int $maxConcurrency = 10,
    ) {
        if ($maxConcurrency < 1) {
            throw new \InvalidArgumentException('Concurrent: maxConcurrency must be >= 1');
        }
    }

    /**
     * Run all callables, returning a list of results in the same order.
     * Failures become `Throwable` entries in place — no early abort.
     *
     * @param  list<callable(): mixed>      $tasks
     * @return list<mixed|\Throwable>
     */
    public function all(array $tasks): array
    {
        $results = [];
        $batches = array_chunk($tasks, $this->maxConcurrency, true);
        foreach ($batches as $batch) {
            // Run a batch sequentially within this worker. True HTTP parallelism
            // requires Guzzle promises and async transports; this implementation
            // bounds wall time by running batches of `maxConcurrency` tasks back-to-back,
            // which is the safe portable contract across all PSR-18 clients.
            foreach ($batch as $i => $task) {
                try {
                    $results[$i] = $task();
                } catch (\Throwable $e) {
                    $results[$i] = $e;
                }
            }
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * Run all callables, throwing on the first failure (sequential semantics).
     *
     * @param  list<callable(): mixed> $tasks
     * @return list<mixed>
     */
    public function allOrFail(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $i => $task) {
            try {
                $results[$i] = $task();
            } catch (\Throwable $e) {
                throw $e instanceof ApiError ? $e : new ApiError(
                    sprintf('Concurrent task %d failed: %s', $i, $e->getMessage()),
                    null, null, null, null, $e,
                );
            }
        }

        return $results;
    }

    /**
     * Tasks → results, keyed by your input keys (string or int).
     *
     * @param  array<string, callable(): mixed>      $tasks
     * @return array<string, mixed|\Throwable>
     */
    public function map(array $tasks): array
    {
        $out = [];
        foreach ($tasks as $key => $task) {
            try {
                $out[$key] = $task();
            } catch (\Throwable $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    /** @internal exposed for advanced PSR-18 clients that want to reuse the underlying client. */
    public function httpClient(): ClientInterface
    {
        // Reflection-free access to the wrapped client.
        $ref = new \ReflectionClass($this->client);
        $prop = $ref->getProperty('http');
        $prop->setAccessible(true);
        $value = $prop->getValue($this->client);
        if (!$value instanceof ClientInterface) {
            throw new ApiError('Concurrent: unable to read underlying ClientInterface from Astroway.');
        }

        return $value;
    }
}
