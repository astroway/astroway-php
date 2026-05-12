<?php

declare(strict_types=1);

namespace Astroway\Internal;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client decorator. Retries on 408/409/429/5xx and network errors with
 * exponential backoff + full jitter. Honors Retry-After (seconds or HTTP-date)
 * on 429.
 */
final class RetryClient implements ClientInterface
{
    /** @var array<int, true> */
    private static array $defaultRetryable = [
        408 => true, 409 => true, 429 => true,
        500 => true, 502 => true, 503 => true, 504 => true,
    ];

    private readonly int $maxRetries;
    private readonly int $baseDelayMs;
    private readonly int $maxDelayMs;
    /** @var array<int, true> */
    private readonly array $retryable;

    /**
     * @param array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>} $config
     */
    public function __construct(
        private readonly ClientInterface $inner,
        array $config = [],
    ) {
        $this->maxRetries = $config['maxRetries'] ?? 2;
        $this->baseDelayMs = $config['baseDelayMs'] ?? 250;
        $this->maxDelayMs = $config['maxDelayMs'] ?? 30_000;
        $this->retryable = isset($config['retryableStatuses'])
            ? array_fill_keys($config['retryableStatuses'], true)
            : self::$defaultRetryable;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->inner->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->sleep($this->jitter($attempt));
                $attempt++;
                continue;
            } catch (ClientExceptionInterface $e) {
                throw $e;
            }

            if (!isset($this->retryable[$response->getStatusCode()]) || $attempt >= $this->maxRetries) {
                return $response;
            }
            $this->sleep($this->computeDelay($response, $attempt));
            $attempt++;
        }
    }

    private function computeDelay(ResponseInterface $response, int $attempt): int
    {
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

        return $this->jitter($attempt);
    }

    private function jitter(int $attempt): int
    {
        $upper = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $attempt));

        return random_int(0, $upper);
    }

    private function sleep(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }
        usleep($delayMs * 1000);
    }
}
