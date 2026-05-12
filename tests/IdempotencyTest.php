<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Internal\Idempotency;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    private const UUID4_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testGenerateKeyReturnsUuid4(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertMatchesRegularExpression(self::UUID4_RE, Idempotency::generateKey());
        }
    }

    public function testKeysAreUnique(): void
    {
        $set = [];
        for ($i = 0; $i < 50; $i++) {
            $set[Idempotency::generateKey()] = true;
        }
        self::assertCount(50, $set);
    }

    public function testPostAttachesIdempotencyKeyByDefault(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->post('/chart', body: []);
        $key = $mock->requests()[0]->getHeaderLine('idempotency-key');
        self::assertMatchesRegularExpression(self::UUID4_RE, $key);
    }

    public function testGetDoesNotAttachIdempotencyKey(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->get('/health');
        self::assertSame('', $mock->requests()[0]->getHeaderLine('idempotency-key'));
    }

    public function testPerCallIdempotencyKeyWins(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->request('POST', '/chart', ['json' => [], 'idempotencyKey' => 'my-key-123']);
        self::assertSame('my-key-123', $mock->requests()[0]->getHeaderLine('idempotency-key'));
    }

    public function testIdempotencyOffDisablesAutoGeneration(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway([
            'apiKey' => 'aw_test_x',
            'httpClient' => $mock,
            'idempotency' => 'off',
        ]);
        $aw->post('/chart', body: []);
        self::assertSame('', $mock->requests()[0]->getHeaderLine('idempotency-key'));
    }

    public function testCustomGeneratorCallable(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $counter = 0;
        $aw = new Astroway([
            'apiKey' => 'aw_test_x',
            'httpClient' => $mock,
            'idempotency' => function () use (&$counter): string {
                return 'test-'.++$counter;
            },
        ]);
        $aw->post('/chart', body: []);
        $aw->post('/chart', body: []);
        self::assertSame('test-1', $mock->requests()[0]->getHeaderLine('idempotency-key'));
        self::assertSame('test-2', $mock->requests()[1]->getHeaderLine('idempotency-key'));
    }

    public function testNamespaceMethodIdempotencyKeyOption(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->synastry()->aspectGrid([], ['idempotencyKey' => 'replay-abc']);
        self::assertSame('replay-abc', $mock->requests()[0]->getHeaderLine('idempotency-key'));
    }
}
