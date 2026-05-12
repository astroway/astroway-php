<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Errors\CalculationError;
use Astroway\Errors\QuotaExceededError;
use Astroway\Errors\RateLimitError;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RefinedErrorsTest extends TestCase
{
    public function testOutOfCreditsCodeClassifiesAsQuotaExceeded(): void
    {
        $aw = $this->makeClient(new Response(400, [], json_encode([
            'ok' => false, 'error' => ['code' => 'OUT_OF_CREDITS', 'message' => 'No credits'],
        ])));
        $this->expectException(QuotaExceededError::class);
        $aw->post('/chart', body: []);
    }

    public function testHttp402ClassifiesAsQuotaExceeded(): void
    {
        $aw = $this->makeClient(new Response(402, [], json_encode([
            'ok' => false, 'error' => ['message' => 'Payment required'],
        ])));
        $this->expectException(QuotaExceededError::class);
        $aw->post('/chart', body: []);
    }

    public function testCalculationErrorCodeClassifies(): void
    {
        $aw = $this->makeClient(new Response(422, [], json_encode([
            'ok' => false, 'error' => ['code' => 'CALCULATION_ERROR', 'message' => 'Ephemeris boundary'],
        ])));
        $this->expectException(CalculationError::class);
        $aw->post('/chart', body: []);
    }

    public function testCreditsRemainingSurfacedOnError(): void
    {
        $aw = $this->makeClient(new Response(
            402,
            ['x-credits-remaining' => '0', 'x-request-id' => 'req_xyz'],
            json_encode(['ok' => false, 'error' => ['code' => 'OUT_OF_CREDITS', 'message' => 'No credits']]),
        ));
        try {
            $aw->post('/chart', body: []);
            self::fail('expected QuotaExceededError');
        } catch (QuotaExceededError $e) {
            self::assertSame(0, $e->creditsRemaining);
            self::assertSame('req_xyz', $e->requestId);
        }
    }

    public function testRateLimitRetryAfterStillAccessible(): void
    {
        $aw = $this->makeClient(new Response(
            429,
            ['retry-after' => '30'],
            json_encode(['ok' => false, 'error' => ['message' => 'Slow down']]),
        ));
        try {
            $aw->post('/chart', body: []);
            self::fail('expected RateLimitError');
        } catch (RateLimitError $e) {
            self::assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function testCreditsRemainingNullWhenHeaderAbsent(): void
    {
        $aw = $this->makeClient(new Response(429, ['retry-after' => '5'], json_encode([
            'ok' => false, 'error' => ['message' => 'Slow'],
        ])));
        try {
            $aw->post('/chart', body: []);
            self::fail('expected RateLimitError');
        } catch (RateLimitError $e) {
            self::assertNull($e->creditsRemaining);
        }
    }

    private function makeClient(Response $response): Astroway
    {
        return new Astroway([
            'apiKey' => 'aw_test_x',
            'httpClient' => new MockHttpClient([$response]),
            'retry' => ['maxRetries' => 0],
        ]);
    }
}
