<?php

declare(strict_types=1);

namespace Astroway;

use Astroway\Errors\APIConnectionError;
use Astroway\Errors\APITimeoutError;
use Astroway\Errors\ApiError;
use Astroway\Errors\Classify;
use Astroway\Internal\CacheKey;
use Astroway\Internal\CachePolicy;
use Astroway\Internal\Idempotency;
use Astroway\Internal\LoggingClient;
use Astroway\Internal\RetryClient;
use Psr\SimpleCache\CacheInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Official AstroWay API client.
 *
 * Example:
 *
 *     $aw = new \Astroway\Astroway(['apiKey' => getenv('ASTROWAY_API_KEY')]);
 *     $chart = $aw->post('/chart', body: [
 *         'date' => '1990-07-14', 'time' => '14:30:00',
 *         'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52,
 *     ]);
 *     echo $chart['angles']['asc']['sign'];
 *
 * Bring Your Own HTTP Client (alpha.2+): pass a PSR-18 client + PSR-17 factories
 * to integrate with Symfony, Laravel, etc. Without overrides we auto-discover
 * via php-http/discovery (Guzzle/Nyholm/Symfony if installed).
 */
/**
 * `Astroway` is non-final since beta.4 so `\Astroway\Testing\MockAstroway` can
 * extend it without re-declaring 100+ namespace service properties. Production
 * users should treat the class as effectively final — the `@api` surface lives
 * on the public methods, not on inheritance.
 */
class Astroway
{
    use HasServices;

    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://api.astroway.info/v1';

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly string $authScheme;
    /** @var string|callable|null */
    private $idempotency;
    /** @var callable(): string */
    private $idempotencyGenerator;
    /** @var array<string, string> */
    private readonly array $defaultHeaders;
    private readonly ?CacheInterface $cache;
    private readonly ?int $cacheTtlSeconds;

    /**
     * @param array{
     *     apiKey?: string,
     *     baseUrl?: string,
     *     authScheme?: 'header'|'bearer',
     *     timeout?: float,
     *     retry?: array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>},
     *     defaultHeaders?: array<string, string>,
     *     httpClient?: ClientInterface,
     *     requestFactory?: RequestFactoryInterface,
     *     streamFactory?: StreamFactoryInterface,
     *     idempotency?: 'auto'|'off'|callable(): string,
     *     cache?: CacheInterface,
     *     cacheTtlSeconds?: int|null,
     * } $options
     */
    public function __construct(array $options)
    {
        if (!isset($options['apiKey']) || $options['apiKey'] === '') {
            throw new ApiError(
                'Astroway: apiKey is required. Get one at '
                .'https://api.astroway.info/dashboard/sign-up — 10,000 credits/month free.',
            );
        }
        $this->apiKey = $options['apiKey'];
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->authScheme = $options['authScheme'] ?? 'header';
        $this->idempotency = $options['idempotency'] ?? 'auto';
        $this->idempotencyGenerator = Idempotency::resolveGenerator($this->idempotency);

        $client = $options['httpClient'] ?? self::discoverClient();
        $http = new RetryClient($client, $options['retry'] ?? []);
        /** @var mixed $logger */
        $logger = $options['logger'] ?? null;
        if ($logger !== null) {
            if (!$logger instanceof LoggerInterface) {
                throw new ApiError('Astroway: `logger` option must implement Psr\\Log\\LoggerInterface.');
            }
            /** @var mixed $metrics */
            $metrics = $options['metrics'] ?? null;
            if ($metrics !== null && !is_callable($metrics)) {
                throw new ApiError('Astroway: `metrics` option must be a callable(array $event): void.');
            }
            /** @var (callable(array<string, mixed>): void)|null $metricsTyped */
            $metricsTyped = $metrics;
            $http = new LoggingClient($http, $logger, $metricsTyped);
        }
        $this->http = $http;
        $this->requestFactory = $options['requestFactory'] ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $options['streamFactory'] ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->defaultHeaders = array_merge(
            $this->buildDefaultHeaders(),
            $options['defaultHeaders'] ?? [],
        );

        $this->cache = $options['cache'] ?? null;
        $this->cacheTtlSeconds = array_key_exists('cacheTtlSeconds', $options)
            ? $options['cacheTtlSeconds']
            : 86400; // 24h default; pure-function endpoints don't actually expire
    }

    private static function discoverClient(): ClientInterface
    {
        try {
            return Psr18ClientDiscovery::find();
        } catch (\Throwable $e) {
            throw new ApiError(
                'Astroway: no PSR-18 HTTP client found. Install one of: '
                .'`composer require guzzlehttp/guzzle` (default) or '
                .'`composer require symfony/http-client nyholm/psr7`. '
                .'Or pass an `httpClient` option explicitly.',
                null,
                null,
                null,
                null,
                $e,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildDefaultHeaders(): array
    {
        $headers = [
            'User-Agent' => sprintf(
                'astroway-sdk-php/%s (PHP/%s; %s)',
                self::VERSION,
                PHP_VERSION,
                strtolower(PHP_OS_FAMILY),
            ),
            'X-Astroway-Channel' => 'sdk-php',
            'Accept' => 'application/json',
        ];
        if ($this->authScheme === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        } else {
            $headers['X-Api-Key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * Low-level request. Most callers use get/post/put/delete instead.
     *
     * `$options['json']` accepts an array, a list, or any object with a public
     * `toArray(): array` method (i.e. any DTO under `Astroway\Dto\*`). The DTO
     * is converted automatically before serialization.
     *
     * `$options['cache']` overrides the default cache policy:
     *   - `true`  — cache regardless of endpoint type (use sparingly; user takes responsibility)
     *   - `false` — skip cache even for deterministic endpoints
     *   - `null` (default) — use the deterministic-endpoint allowlist
     *
     * @param array{
     *     query?: array<string, scalar|array<int|string, scalar>>,
     *     json?: array<string, mixed>|list<mixed>|object,
     *     headers?: array<string, string>,
     *     idempotencyKey?: string,
     *     cache?: bool|null,
     *     cacheTtlSeconds?: int|null,
     * } $options
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        if (isset($options['json']) && is_object($options['json']) && method_exists($options['json'], 'toArray')) {
            $options['json'] = $options['json']->toArray();
        }

        $cacheDecision = $options['cache'] ?? null;
        unset($options['cache']);
        $perCallTtl = $options['cacheTtlSeconds'] ?? null;
        unset($options['cacheTtlSeconds']);
        $cacheKey = null;
        if ($this->cache !== null && $cacheDecision !== false) {
            $shouldCache = $cacheDecision === true
                ? true
                : CachePolicy::isDeterministic($path);
            if ($shouldCache) {
                /** @var array<string, mixed>|list<mixed>|null $bodyForKey */
                $bodyForKey = $options['json'] ?? null;
                $cacheKey = CacheKey::build($method, $path, $bodyForKey);
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }

        // Auto-attach Idempotency-Key on POST unless caller already supplied one or policy is 'off'.
        $headers = $options['headers'] ?? [];
        $hasIdempotencyHeader = false;
        foreach ($headers as $name => $_) {
            if (strtolower((string) $name) === 'idempotency-key') {
                $hasIdempotencyHeader = true;
                break;
            }
        }
        if (isset($options['idempotencyKey'])) {
            $headers['Idempotency-Key'] = $options['idempotencyKey'];
            unset($options['idempotencyKey']);
        } elseif (!$hasIdempotencyHeader && Idempotency::shouldAttach($this->idempotency, $method)) {
            $headers['Idempotency-Key'] = ($this->idempotencyGenerator)();
        }
        $options['headers'] = $headers;
        $request = $this->buildRequest($method, $path, $options);

        try {
            $response = $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            $msg = strtolower($e->getMessage());
            $isTimeout = str_contains($msg, 'timed out') || str_contains($msg, 'timeout');

            throw $isTimeout
                ? new APITimeoutError(sprintf('Request to %s timed out', $path), null, null, null, null, $e)
                : new APIConnectionError(sprintf('Network error calling %s: %s', $path, $e->getMessage()), null, null, null, null, $e);
        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            throw new APIConnectionError(sprintf('Transport error calling %s: %s', $path, $e->getMessage()), null, null, null, null, $e);
        }

        $this->raiseForResponse($response);

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $body;
        }
        // Endpoints wrap responses as { ok, data, error } — unwrap data when present.
        $result = $payload['data'] ?? $payload;
        if ($cacheKey !== null) {
            $this->cache->set($cacheKey, $result, $perCallTtl ?? $this->cacheTtlSeconds);
        }

        return $result;
    }

    /**
     * @param array<string, scalar|array<int|string, scalar>> $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed>|list<mixed>|object|null     $body  Array, list, or DTO with `toArray()`.
     * @param array<string, scalar|array<int|string, scalar>>  $query
     */
    public function post(string $path, array|object|null $body = null, array $query = [], ?bool $cache = null): mixed
    {
        $opts = [];
        if ($body !== null) {
            $opts['json'] = $body;
        }
        if ($query !== []) {
            $opts['query'] = $query;
        }
        if ($cache !== null) {
            $opts['cache'] = $cache;
        }

        return $this->request('POST', $path, $opts);
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     */
    public function put(string $path, ?array $body = null): mixed
    {
        return $this->request('PUT', $path, $body !== null ? ['json' => $body] : []);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Get a concurrent helper for batch dispatch.
     *
     * ```php
     * $charts = $aw->concurrent(5)->all([
     *     fn() => $aw->charts()->natal($r1),
     *     fn() => $aw->charts()->natal($r2),
     * ]);
     * ```
     */
    public function concurrent(int $maxConcurrency = 10): Concurrent
    {
        return new Concurrent($this, $maxConcurrency);
    }

    /**
     * @param array{
     *     query?: array<string, scalar|array<int|string, scalar>>,
     *     json?: array<string, mixed>|list<mixed>,
     *     headers?: array<string, string>,
     * } $options
     */
    private function buildRequest(string $method, string $path, array $options): RequestInterface
    {
        $uri = $this->baseUrl.'/'.ltrim($path, '/');
        if (!empty($options['query'])) {
            $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query($options['query']);
        }

        $request = $this->requestFactory->createRequest($method, $uri);
        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new ApiError('Failed to encode request body as JSON: '.json_last_error_msg());
            }
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        return $request;
    }

    private function raiseForResponse(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        $requestId = $response->getHeaderLine('x-request-id') ?: null;
        $retryAfter = self::parseIntHeader($response->getHeaderLine('retry-after'));
        $creditsRemaining = self::parseIntHeader($response->getHeaderLine('x-credits-remaining'));

        $rawBody = (string) $response->getBody();
        $body = null;
        $code = null;
        $message = sprintf('%d %s', $status, $response->getReasonPhrase());
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
                $err = $decoded['error'] ?? null;
                if (is_array($err)) {
                    if (isset($err['code']) && is_string($err['code'])) {
                        $code = $err['code'];
                    }
                    if (isset($err['message']) && is_string($err['message'])) {
                        $message = $err['message'];
                    }
                }
            } else {
                $body = $rawBody;
            }
        }

        throw Classify::fromStatus(
            $status, $message, $code, $body, $requestId, $retryAfter, $creditsRemaining,
        );
    }

    private static function parseIntHeader(string $value): ?int
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) (float) $value;
    }
}
