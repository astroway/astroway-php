<?php

declare(strict_types=1);

namespace Astroway;

use Astroway\Errors\APIConnectionError;
use Astroway\Errors\APITimeoutError;
use Astroway\Errors\ApiError;
use Astroway\Errors\Classify;
use Astroway\Internal\RetryMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

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
 */
final class Astroway
{
    public const VERSION = '0.1.0-alpha.1';

    public const DEFAULT_BASE_URL = 'https://api.astroway.info/v1';

    private readonly Client $http;
    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly string $authScheme;
    private readonly float $timeout;

    /**
     * @param array{
     *     apiKey: string,
     *     baseUrl?: string,
     *     authScheme?: 'header'|'bearer',
     *     timeout?: float,
     *     retry?: array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>},
     *     defaultHeaders?: array<string, string>,
     *     handlerStack?: HandlerStack,
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
        $this->timeout = (float) ($options['timeout'] ?? 30.0);

        $stack = $options['handlerStack'] ?? HandlerStack::create();
        // Push retry first so it sits closest to the actual transport — retried
        // requests bypass any user-pushed middleware that's already on the stack.
        $stack->push(RetryMiddleware::factory($options['retry'] ?? []), 'astroway.retry');

        $headers = array_merge(
            $this->defaultHeaders(),
            $options['defaultHeaders'] ?? [],
        );

        $this->http = new Client([
            'base_uri' => $this->baseUrl.'/',
            'handler' => $stack,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'headers' => $headers,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
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
     * @param array<string, scalar|array> $options Standard Guzzle options (json, query, headers, ...)
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        $relativePath = ltrim($path, '/');
        try {
            $response = $this->http->request($method, $relativePath, $options);
        } catch (ConnectException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out');

            throw $isTimeout
                ? new APITimeoutError(sprintf('Request to %s timed out after %ss', $path, $this->timeout), null, null, null, null, $e)
                : new APIConnectionError(sprintf('Network error calling %s: %s', $path, $e->getMessage()), null, null, null, null, $e);
        } catch (TransferException $e) {
            throw new APIConnectionError(sprintf('Transfer error calling %s: %s', $path, $e->getMessage()), null, null, null, null, $e);
        } catch (GuzzleException $e) {
            throw new ApiError(sprintf('HTTP error calling %s: %s', $path, $e->getMessage()), null, null, null, null, $e);
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
        return $payload['data'] ?? $payload;
    }

    /**
     * @param array<string, scalar|array> $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, scalar|array>|null $body
     * @param array<string, scalar|array>      $query
     */
    public function post(string $path, ?array $body = null, array $query = []): mixed
    {
        $opts = [];
        if ($body !== null) {
            $opts['json'] = $body;
        }
        if ($query !== []) {
            $opts['query'] = $query;
        }

        return $this->request('POST', $path, $opts);
    }

    /**
     * @param array<string, scalar|array>|null $body
     */
    public function put(string $path, ?array $body = null): mixed
    {
        return $this->request('PUT', $path, $body !== null ? ['json' => $body] : []);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    private function raiseForResponse(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        $requestId = $response->getHeaderLine('x-request-id') ?: null;
        $retryAfterRaw = $response->getHeaderLine('retry-after');
        $retryAfter = ($retryAfterRaw !== '' && is_numeric($retryAfterRaw))
            ? (int) (float) $retryAfterRaw
            : null;

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

        throw Classify::fromStatus($status, $message, $code, $body, $requestId, $retryAfter);
    }
}
