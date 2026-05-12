<?php

declare(strict_types=1);

namespace Astroway\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal recording PSR-18 client for tests. Pops responses from a FIFO queue;
 * each `sendRequest` records the incoming request and returns the next response
 * (or throws if a Throwable was queued instead).
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param iterable<ResponseInterface|\Throwable> $responses
     */
    public function __construct(iterable $responses = [])
    {
        foreach ($responses as $r) {
            $this->queue[] = $r;
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('MockHttpClient: queue exhausted, no response left.');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }
}
