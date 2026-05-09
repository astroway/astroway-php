<?php

declare(strict_types=1);

namespace Astroway\Internal;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware: retries on 408/409/429/5xx and connection failures, with
 * exponential backoff + full jitter. Honors Retry-After (seconds or HTTP-date)
 * on 429.
 *
 * Wire it via:
 *
 *     $stack->push(RetryMiddleware::factory($config));
 */
final class RetryMiddleware
{
    /** @var array<int, true> */
    private static array $defaultRetryable = [
        408 => true, 409 => true, 429 => true,
        500 => true, 502 => true, 503 => true, 504 => true,
    ];

    /**
     * @param array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>} $config
     *
     * @return callable(callable): callable
     */
    public static function factory(array $config = []): callable
    {
        $maxRetries = $config['maxRetries'] ?? 2;
        $baseDelayMs = $config['baseDelayMs'] ?? 250;
        $maxDelayMs = $config['maxDelayMs'] ?? 30_000;
        $retryable = isset($config['retryableStatuses'])
            ? array_fill_keys($config['retryableStatuses'], true)
            : self::$defaultRetryable;

        return function (callable $handler) use ($maxRetries, $baseDelayMs, $maxDelayMs, $retryable): callable {
            return self::makeHandler($handler, 0, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable);
        };
    }

    /**
     * @param array<int, true> $retryable
     *
     * @return callable(RequestInterface, array): PromiseInterface
     */
    private static function makeHandler(
        callable $handler,
        int $attempt,
        int $maxRetries,
        int $baseDelayMs,
        int $maxDelayMs,
        array $retryable,
    ): callable {
        return function (RequestInterface $request, array $options) use (
            $handler, $attempt, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable,
        ): PromiseInterface {
            return $handler($request, $options)->then(
                static function (ResponseInterface $response) use (
                    $request, $options, $handler, $attempt, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable,
                ) {
                    if (
                        !isset($retryable[$response->getStatusCode()])
                        || $attempt >= $maxRetries
                    ) {
                        return $response;
                    }
                    self::sleep(self::computeDelay($response, $attempt, $baseDelayMs, $maxDelayMs));
                    $next = self::makeHandler($handler, $attempt + 1, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable);

                    return $next($request, $options);
                },
                static function (\Throwable $e) use (
                    $request, $options, $handler, $attempt, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable,
                ) {
                    if ($attempt >= $maxRetries) {
                        throw $e;
                    }
                    self::sleep(self::jitter($attempt, $baseDelayMs, $maxDelayMs));
                    $next = self::makeHandler($handler, $attempt + 1, $maxRetries, $baseDelayMs, $maxDelayMs, $retryable);

                    return $next($request, $options);
                },
            );
        };
    }

    private static function computeDelay(
        ResponseInterface $response,
        int $attempt,
        int $baseDelayMs,
        int $maxDelayMs,
    ): int {
        $retryAfter = $response->getHeaderLine('retry-after');
        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                $seconds = (float) $retryAfter;
                if ($seconds >= 0) {
                    return (int) ($seconds * 1000);
                }
            }
            $when = strtotime($retryAfter);
            if ($when !== false) {
                $delta = ($when - time()) * 1000;

                return $delta > 0 ? $delta : 0;
            }
        }

        return self::jitter($attempt, $baseDelayMs, $maxDelayMs);
    }

    private static function jitter(int $attempt, int $baseDelayMs, int $maxDelayMs): int
    {
        $upper = min($maxDelayMs, $baseDelayMs * (2 ** $attempt));

        return random_int(0, $upper);
    }

    private static function sleep(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }
        usleep($delayMs * 1000);
    }
}
