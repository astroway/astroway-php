<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Errors\ApiError;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

final class LoggingTest extends TestCase
{
    /**
     * @param iterable<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    private function newAstroway(iterable $responses, ?LoggerInterface $logger = null, ?callable $metrics = null): array
    {
        $f = new Psr17Factory();
        $client = new MockHttpClient($responses);
        $opts = [
            'apiKey'         => 'aw_test_x',
            'httpClient'     => $client,
            'requestFactory' => $f,
            'streamFactory'  => $f,
            'retry'          => ['maxRetries' => 0],
        ];
        if ($logger !== null) {
            $opts['logger'] = $logger;
        }
        if ($metrics !== null) {
            $opts['metrics'] = $metrics;
        }

        return [new Astroway($opts), $client];
    }

    public function testNoLoggerByDefaultIsNoop(): void
    {
        [$aw] = $this->newAstroway([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"data":{"v":1}}'),
        ]);
        $r = $aw->post('/chart', ['date' => '1990-01-01']);
        self::assertSame(['v' => 1], $r);
    }

    public function testLoggerEmitsRequestThenResponseRecord(): void
    {
        $logger = new RecordingLogger();
        [$aw] = $this->newAstroway([
            new Response(200, [
                'Content-Type'        => 'application/json',
                'X-Request-Id'        => 'srv-1234',
                'X-Credits-Remaining' => '9999',
            ], '{"ok":true,"data":{"x":1}}'),
        ], $logger);
        $aw->post('/chart', ['date' => '1990-01-01']);

        self::assertCount(2, $logger->records);

        [$req, $res] = $logger->records;
        self::assertSame('astroway.request', $req['message']);
        self::assertSame('debug', $req['level']);
        self::assertSame('POST', $req['context']['method']);
        self::assertSame('/v1/chart', $req['context']['path']);
        self::assertNotEmpty($req['context']['astroway_trace_id']);

        self::assertSame('astroway.response', $res['message']);
        self::assertSame('debug', $res['level']);
        self::assertSame(200, $res['context']['status']);
        self::assertSame($req['context']['astroway_trace_id'], $res['context']['astroway_trace_id']);
        self::assertSame('srv-1234', $res['context']['request_id']);
        self::assertSame(9999, $res['context']['credits_remaining']);
    }

    public function testLoggerLevelClimbsWithStatus(): void
    {
        $logger = new RecordingLogger();
        [$aw] = $this->newAstroway([
            new Response(404, ['Content-Type' => 'application/json'], '{"ok":false,"error":{"code":"NOT_FOUND","message":"x"}}'),
        ], $logger);

        try {
            $aw->post('/chart', ['date' => '1990-01-01']);
        } catch (\Throwable) {
            // expected — 404 raises NotFoundError
        }

        $levels = array_column($logger->records, 'level');
        self::assertContains('debug', $levels);     // request
        self::assertContains('warning', $levels);   // 404 response
    }

    public function testTraceIdHeaderForwardedToServer(): void
    {
        $logger = new RecordingLogger();
        [$aw, $client] = $this->newAstroway([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"data":null}'),
        ], $logger);
        $aw->post('/chart', ['date' => '1990-01-01']);

        $sent = $client->requests()[0];
        self::assertNotSame('', $sent->getHeaderLine('X-Astroway-Trace-Id'));
        self::assertSame(
            $sent->getHeaderLine('X-Astroway-Trace-Id'),
            $logger->records[0]['context']['astroway_trace_id'],
        );
    }

    public function testCallerSuppliedTraceIdIsRespected(): void
    {
        $logger = new RecordingLogger();
        [$aw, $client] = $this->newAstroway([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"data":null}'),
        ], $logger);
        $aw->request('POST', '/chart', [
            'json'    => ['date' => '1990-01-01'],
            'headers' => ['X-Astroway-Trace-Id' => 'caller-trace-deadbeef'],
        ]);

        $sent = $client->requests()[0];
        self::assertSame('caller-trace-deadbeef', $sent->getHeaderLine('X-Astroway-Trace-Id'));
        self::assertSame('caller-trace-deadbeef', $logger->records[0]['context']['astroway_trace_id']);
    }

    public function testMetricsCallbackReceivesEvents(): void
    {
        $events = [];
        $metrics = static function (array $event) use (&$events): void {
            $events[] = $event;
        };
        $logger = new RecordingLogger();
        [$aw] = $this->newAstroway([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"data":null}'),
        ], $logger, $metrics);
        $aw->post('/chart', ['date' => '1990-01-01']);

        self::assertSame(['request', 'response'], array_column($events, 'event'));
    }

    public function testMetricsCallbackErrorDoesNotBreakRequest(): void
    {
        $metrics = static function (array $event): void {
            throw new \RuntimeException('metrics broken');
        };
        $logger = new RecordingLogger();
        [$aw] = $this->newAstroway([
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true,"data":{"x":1}}'),
        ], $logger, $metrics);

        $r = $aw->post('/chart', ['date' => '1990-01-01']);
        self::assertSame(['x' => 1], $r);
    }

    public function testInvalidLoggerOptionRaisesApiError(): void
    {
        $f = new Psr17Factory();
        $this->expectException(ApiError::class);
        $this->expectExceptionMessageMatches('/logger.+LoggerInterface/');
        new Astroway([
            'apiKey'         => 'x',
            'httpClient'     => new MockHttpClient([new Response(200)]),
            'requestFactory' => $f,
            'streamFactory'  => $f,
            'logger'         => 'not-a-logger',
        ]);
    }

    public function testInvalidMetricsOptionRaisesApiError(): void
    {
        $f = new Psr17Factory();
        $this->expectException(ApiError::class);
        $this->expectExceptionMessageMatches('/metrics.+callable/');
        new Astroway([
            'apiKey'         => 'x',
            'httpClient'     => new MockHttpClient([new Response(200)]),
            'requestFactory' => $f,
            'streamFactory'  => $f,
            'logger'         => new RecordingLogger(),
            'metrics'        => 'not-a-callable',
        ]);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
