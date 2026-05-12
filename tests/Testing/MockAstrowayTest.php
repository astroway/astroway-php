<?php

declare(strict_types=1);

namespace Astroway\Tests\Testing;

use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\QuotaExceededError;
use Astroway\Errors\RateLimitError;
use Astroway\Testing\MockApiError;
use Astroway\Testing\MockAstroway;
use PHPUnit\Framework\TestCase;

final class MockAstrowayTest extends TestCase
{
    public function testReturnsRegisteredFixture(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', ['angles' => ['asc' => 'Aries']]);

        $r = $mock->request('POST', '/chart', ['json' => ['date' => '1990-01-01']]);

        self::assertSame(['angles' => ['asc' => 'Aries']], $r);
        self::assertSame(1, $mock->callCount());
        self::assertSame('POST', $mock->calls[0]['method']);
        self::assertSame('/chart', $mock->calls[0]['path']);
        self::assertSame(['date' => '1990-01-01'], $mock->calls[0]['body']);
    }

    public function testFixtureFactoryReceivesContext(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', static fn (array $ctx): array => [
            'echoed' => $ctx['body'],
            'index'  => $ctx['callIndex'],
        ]);
        $mock->respond('POST', '/chart', ['second' => true]);

        $first  = $mock->request('POST', '/chart', ['json' => ['x' => 1]]);
        $second = $mock->request('POST', '/chart', ['json' => ['x' => 2]]);

        self::assertSame(['echoed' => ['x' => 1], 'index' => 0], $first);
        self::assertSame(['second' => true], $second);
    }

    public function testNoFixtureRaisesHelpfulError(): void
    {
        $mock = new MockAstroway();
        try {
            $mock->request('POST', '/chart');
            $this->fail('Expected ApiError');
        } catch (ApiError $e) {
            self::assertStringContainsString('MockAstroway: no fixture for POST /chart', $e->getMessage());
            self::assertStringContainsString("respond('POST', '/chart'", $e->getMessage());
        }
    }

    public function testCallsForFiltersByPathAndMethod(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', ['ok' => 1]);
        $mock->respond('POST', '/synastry', ['ok' => 2]);
        $mock->request('POST', '/chart');
        $mock->request('POST', '/chart');
        $mock->request('POST', '/synastry');

        self::assertCount(2, $mock->callsFor('/chart'));
        self::assertCount(2, $mock->callsFor('/chart', 'POST'));
        self::assertCount(0, $mock->callsFor('/chart', 'GET'));
        self::assertCount(1, $mock->callsFor('/synastry'));
    }

    public function testResetClearsCallsAndFixtures(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', ['ok' => 1]);
        $mock->request('POST', '/chart');
        $mock->reset();

        self::assertSame(0, $mock->callCount());
        $this->expectException(ApiError::class);
        $mock->request('POST', '/chart');
    }

    public function testThrowableFixtureIsThrown(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', new \RuntimeException('boom'));

        try {
            $mock->request('POST', '/chart');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
            // The thrown error is still recorded in calls[].
            self::assertSame(1, $mock->callCount());
            self::assertSame($e, $mock->calls[0]['resolved']);
        }
    }

    public function testMockApiErrorClassifies401AsAuthError(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart',
            MockApiError::make(status: 401, code: 'INVALID_API_KEY', message: 'bad key'),
        );

        try {
            $mock->request('POST', '/chart');
            $this->fail('Expected AuthenticationError');
        } catch (AuthenticationError $e) {
            self::assertSame(401, $e->status);
        }
    }

    public function testMockApiErrorClassifies429AsRateLimit(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart',
            MockApiError::make(status: 429, retryAfterSeconds: 17),
        );

        try {
            $mock->request('POST', '/chart');
            $this->fail('Expected RateLimitError');
        } catch (RateLimitError $e) {
            self::assertSame(17, $e->retryAfterSeconds);
        }
    }

    public function testMockApiErrorClassifiesQuotaCode(): void
    {
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart',
            MockApiError::make(status: 402, code: 'OUT_OF_CREDITS', creditsRemaining: 0),
        );

        $this->expectException(QuotaExceededError::class);
        $mock->request('POST', '/chart');
    }

    public function testInheritsNamespaceServices(): void
    {
        // Smoke test: namespace services compile with MockAstroway as the client
        // (since MockAstroway extends Astroway, all 100+ services accept it).
        $mock = new MockAstroway();
        $mock->respond('POST', '/chart', ['inherited' => true]);

        $service = $mock->chart();
        $r = $service->compute(['date' => '1990-01-01']);

        self::assertSame(['inherited' => true], $r);
        self::assertSame('/chart', $mock->calls[0]['path']);
    }
}
