<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\BadRequestError;
use Astroway\Errors\RateLimitError;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AstrowayTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: Response, error: mixed, options: array<string, mixed>}> */
    private array $history = [];

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(ApiError::class);
        new Astroway(['apiKey' => '']);
    }

    public function testXApiKeyHeaderByDefault(): void
    {
        $aw = $this->makeClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['ok' => true]])),
        ]);
        $aw->post('/chart', body: [
            'date' => '1990-07-14', 'time' => '14:30:00',
            'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52,
        ]);
        /** @var RequestInterface $req */
        $req = $this->history[0]['request'];
        self::assertSame('aw_test_secret', $req->getHeaderLine('x-api-key'));
        self::assertSame('', $req->getHeaderLine('authorization'));
        self::assertSame('sdk-php', $req->getHeaderLine('x-astroway-channel'));
        self::assertStringStartsWith('astroway-sdk-php/', $req->getHeaderLine('user-agent'));
    }

    public function testBearerWhenAuthSchemeBearer(): void
    {
        $aw = $this->makeClient(
            [new Response(200, [], json_encode(['ok' => true, 'data' => []]))],
            authScheme: 'bearer',
        );
        $aw->post('/chart', body: []);
        /** @var RequestInterface $req */
        $req = $this->history[0]['request'];
        self::assertSame('Bearer aw_test_secret', $req->getHeaderLine('authorization'));
        self::assertSame('', $req->getHeaderLine('x-api-key'));
    }

    public function testRaisesAuthenticationErrorOn401(): void
    {
        $aw = $this->makeClient([
            new Response(401, [], json_encode([
                'ok' => false,
                'error' => ['code' => 'INVALID_KEY', 'message' => 'API key is invalid'],
            ])),
        ], retry: ['maxRetries' => 0]);

        try {
            $aw->post('/chart', body: []);
            self::fail('expected AuthenticationError');
        } catch (AuthenticationError $e) {
            self::assertSame(401, $e->status);
            self::assertSame('INVALID_KEY', $e->errorCode);
            self::assertStringContainsString('invalid', strtolower($e->getMessage()));
        }
    }

    public function testRaisesRateLimitWithRetryAfter(): void
    {
        $aw = $this->makeClient([
            new Response(429, ['retry-after' => '15'], json_encode([
                'ok' => false,
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Slow down'],
            ])),
        ], retry: ['maxRetries' => 0]);

        try {
            $aw->post('/chart', body: []);
            self::fail('expected RateLimitError');
        } catch (RateLimitError $e) {
            self::assertSame(15, $e->retryAfterSeconds);
        }
    }

    public function testRaisesBadRequestOn400(): void
    {
        $aw = $this->makeClient([
            new Response(400, [], json_encode([
                'ok' => false,
                'error' => ['code' => 'BAD_REQUEST', 'message' => 'missing field'],
            ])),
        ], retry: ['maxRetries' => 0]);

        $this->expectException(BadRequestError::class);
        $aw->post('/chart', body: []);
    }

    public function testRequestIdCapturedFromXRequestId(): void
    {
        $aw = $this->makeClient([
            new Response(500, ['x-request-id' => 'req_xyz'], json_encode([
                'ok' => false,
                'error' => ['message' => 'oops'],
            ])),
        ], retry: ['maxRetries' => 0]);

        try {
            $aw->post('/chart', body: []);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('req_xyz', $e->requestId);
        }
    }

    public function testUnwrapsDataEnvelopeOnSuccess(): void
    {
        $payload = ['angles' => ['asc' => ['sign' => 'leo', 'degree' => 12.34]]];
        $aw = $this->makeClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => $payload])),
        ]);
        $result = $aw->post('/chart', body: []);
        self::assertSame($payload, $result);
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $aw = $this->makeClient([
            new Response(429, ['retry-after' => '0'], '{"ok":false,"error":{"message":"slow"}}'),
            new Response(200, [], '{"ok":true,"data":{"retried":true}}'),
        ], retry: ['maxRetries' => 2, 'baseDelayMs' => 1, 'maxDelayMs' => 5]);

        $result = $aw->post('/chart', body: []);
        self::assertSame(['retried' => true], $result);
        self::assertCount(2, $this->history);
    }

    public function testDoesNotRetry401(): void
    {
        $aw = $this->makeClient([
            new Response(401, [], '{"ok":false,"error":{"code":"INVALID_KEY"}}'),
        ], retry: ['maxRetries' => 2, 'baseDelayMs' => 1]);

        $this->expectException(AuthenticationError::class);
        try {
            $aw->post('/chart', body: []);
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    /**
     * @param list<Response>                                                                          $responses
     * @param array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>} $retry
     */
    private function makeClient(
        array $responses,
        string $authScheme = 'header',
        array $retry = [],
    ): Astroway {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $aw = new Astroway([
            'apiKey' => 'aw_test_secret',
            'authScheme' => $authScheme,
            'retry' => $retry,
            'handlerStack' => $stack,
        ]);

        // Push history AFTER Astroway pushes its retry middleware so the
        // history middleware sits closer to the handler and records each
        // retry attempt as a separate entry (instead of one entry for the
        // overall retried request).
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return $aw;
    }
}
