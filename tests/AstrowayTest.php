<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\BadRequestError;
use Astroway\Errors\RateLimitError;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AstrowayTest extends TestCase
{
    private MockHttpClient $mock;

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
        $req = $this->mock->requests()[0];
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
        $req = $this->mock->requests()[0];
        self::assertSame('Bearer aw_test_secret', $req->getHeaderLine('authorization'));
        self::assertSame('', $req->getHeaderLine('x-api-key'));
    }

    public function testLangOptionSetsAcceptLanguage(): void
    {
        $this->mock = new MockHttpClient([new Response(200, [], json_encode(['ok' => true, 'data' => []]))]);
        $aw = new Astroway([
            'apiKey' => 'aw_test',
            'lang' => 'hi',
            'httpClient' => $this->mock,
        ]);
        $aw->post('/horoscope/daily', body: ['sign' => 'leo']);
        $req = $this->mock->requests()[0];
        self::assertSame('hi', $req->getHeaderLine('accept-language'));
        self::assertSame('hi', $aw->lang);
    }

    public function testLangUnsetEmitsNoAcceptLanguage(): void
    {
        $this->mock = new MockHttpClient([new Response(200, [], json_encode(['ok' => true, 'data' => []]))]);
        $aw = new Astroway([
            'apiKey' => 'aw_test',
            'httpClient' => $this->mock,
        ]);
        $aw->post('/horoscope/daily', body: ['sign' => 'leo']);
        $req = $this->mock->requests()[0];
        self::assertSame('', $req->getHeaderLine('accept-language'));
        self::assertNull($aw->lang);
    }

    public function testDefaultHeadersAcceptLanguageWinsOverLang(): void
    {
        $this->mock = new MockHttpClient([new Response(200, [], json_encode(['ok' => true, 'data' => []]))]);
        $aw = new Astroway([
            'apiKey' => 'aw_test',
            'lang' => 'hi',
            'defaultHeaders' => ['Accept-Language' => 'de'],
            'httpClient' => $this->mock,
        ]);
        $aw->post('/horoscope/daily', body: ['sign' => 'leo']);
        $req = $this->mock->requests()[0];
        self::assertSame('de', $req->getHeaderLine('accept-language'));
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
        self::assertCount(2, $this->mock->requests());
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
            self::assertCount(1, $this->mock->requests());
        }
    }

    public function testQueryStringEncoded(): void
    {
        $aw = $this->makeClient([
            new Response(200, [], '{"ok":true,"data":{"ok":true}}'),
        ]);
        $aw->get('/geo/search', ['q' => 'Kyiv, UA', 'limit' => 3]);
        $req = $this->mock->requests()[0];
        $uri = (string) $req->getUri();
        self::assertStringContainsString('q=Kyiv%2C+UA', $uri);
        self::assertStringContainsString('limit=3', $uri);
    }

    public function testJsonBodySerialized(): void
    {
        $aw = $this->makeClient([
            new Response(200, [], '{"ok":true,"data":{}}'),
        ]);
        $aw->post('/chart', body: ['date' => '1990-07-14', 'tags' => ['a', 'b']]);
        $req = $this->mock->requests()[0];
        self::assertSame('application/json', $req->getHeaderLine('content-type'));
        $body = json_decode((string) $req->getBody(), true);
        self::assertSame(['date' => '1990-07-14', 'tags' => ['a', 'b']], $body);
    }

    public function testCustomHttpClientInjected(): void
    {
        // BYOC contract: passing httpClient explicitly skips discovery.
        $custom = new MockHttpClient([
            new Response(200, [], '{"ok":true,"data":{"injected":true}}'),
        ]);
        $aw = new Astroway([
            'apiKey' => 'aw_test_secret',
            'httpClient' => $custom,
        ]);
        $result = $aw->post('/chart', body: []);
        self::assertSame(['injected' => true], $result);
        self::assertCount(1, $custom->requests());
    }

    /**
     * @param list<\Nyholm\Psr7\Response>                                                                 $responses
     * @param array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, retryableStatuses?: list<int>} $retry
     */
    private function makeClient(
        array $responses,
        string $authScheme = 'header',
        array $retry = [],
    ): Astroway {
        $this->mock = new MockHttpClient($responses);

        return new Astroway([
            'apiKey' => 'aw_test_secret',
            'authScheme' => $authScheme,
            'retry' => $retry,
            'httpClient' => $this->mock,
        ]);
    }
}
