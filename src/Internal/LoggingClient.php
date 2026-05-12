<?php

declare(strict_types=1);

namespace Astroway\Internal;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-18 client decorator that emits PSR-3 log records on request, response,
 * and exception. Wraps the inner client so retries (handled by RetryClient
 * one layer below) all surface here as separate request/response pairs.
 *
 * A trace id is generated per `sendRequest()` call (UUID4 hex) and added to
 * every log record's context under `astroway_trace_id` so log pipelines can
 * correlate the request/response/error trio. The same id is forwarded to the
 * server via the `X-Astroway-Trace-Id` header — if you already have request
 * tracing on your side (Datadog, Sentry, OpenTelemetry), pass the upstream
 * trace id via `X-Astroway-Trace-Id` and we'll respect it instead of minting
 * a fresh one.
 *
 * The `metrics` callback is optional and receives a fully-formed event dict
 * (`event` key is `request` | `response` | `error`). Useful for incrementing
 * Prometheus counters, StatsD timers, or feeding APMs without re-parsing logs.
 */
final class LoggingClient implements ClientInterface
{
    /** @var (callable(array<string, mixed>): void)|null */
    private $metrics;

    /**
     * @param (callable(array<string, mixed>): void)|null $metrics
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly LoggerInterface $logger,
        ?callable $metrics = null,
    ) {
        $this->metrics = $metrics;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $traceId = $request->getHeaderLine('X-Astroway-Trace-Id');
        if ($traceId === '') {
            $traceId = bin2hex(random_bytes(8));
            $request = $request->withHeader('X-Astroway-Trace-Id', $traceId);
        }

        $start = hrtime(true);
        $baseCtx = [
            'astroway_trace_id' => $traceId,
            'method'            => $request->getMethod(),
            'path'              => $request->getUri()->getPath(),
        ];
        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        if ($idempotencyKey !== '') {
            $baseCtx['idempotency_key'] = $idempotencyKey;
        }

        $this->logger->debug('astroway.request', $baseCtx);
        $this->emitMetric(['event' => 'request'] + $baseCtx);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $e) {
            $latencyMs = (hrtime(true) - $start) / 1_000_000.0;
            $errCtx = $baseCtx + [
                'exception'  => $e,
                'latency_ms' => round($latencyMs, 2),
            ];
            $this->logger->error('astroway.error', $errCtx);
            $this->emitMetric(['event' => 'error'] + $errCtx);
            throw $e;
        }

        $latencyMs = (hrtime(true) - $start) / 1_000_000.0;
        $status = $response->getStatusCode();
        $level = match (true) {
            $status >= 500 => LogLevel::ERROR,
            $status >= 400 => LogLevel::WARNING,
            default        => LogLevel::DEBUG,
        };
        $resCtx = $baseCtx + [
            'status'     => $status,
            'latency_ms' => round($latencyMs, 2),
        ];
        $serverRequestId = $response->getHeaderLine('x-request-id');
        if ($serverRequestId !== '') {
            $resCtx['request_id'] = $serverRequestId;
        }
        $credits = $response->getHeaderLine('x-credits-remaining');
        if ($credits !== '') {
            $resCtx['credits_remaining'] = (int) $credits;
        }

        $this->logger->log($level, 'astroway.response', $resCtx);
        $this->emitMetric(['event' => 'response'] + $resCtx);

        return $response;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function emitMetric(array $event): void
    {
        if ($this->metrics === null) {
            return;
        }
        try {
            ($this->metrics)($event);
        } catch (\Throwable) {
            // Metrics handlers must never break the request path.
        }
    }
}
