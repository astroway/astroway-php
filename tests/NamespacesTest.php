<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Namespaces\BaziService;
use Astroway\Namespaces\HumanDesignService;
use Astroway\Namespaces\SynastryService;
use Astroway\Namespaces\TransitsService;
use Astroway\Namespaces\VedicService;
use Astroway\Tests\Support\MockHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class NamespacesTest extends TestCase
{
    public function testWellKnownAccessorsReturnTypedServices(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => new MockHttpClient()]);
        self::assertInstanceOf(SynastryService::class, $aw->synastry());
        self::assertInstanceOf(TransitsService::class, $aw->transits());
        self::assertInstanceOf(VedicService::class, $aw->vedic());
        self::assertInstanceOf(BaziService::class, $aw->bazi());
        self::assertInstanceOf(HumanDesignService::class, $aw->humanDesign());
    }

    public function testServiceAccessorsAreMemoized(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => new MockHttpClient()]);
        self::assertSame($aw->synastry(), $aw->synastry());
        self::assertSame($aw->vedic(), $aw->vedic());
    }

    public function testAspectGridPostsToCorrectPathAndUnwrapsEnvelope(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['aspects' => [['a' => 'sun', 'b' => 'moon']]]])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $result = $aw->synastry()->aspectGrid(['foo' => 'bar']);
        self::assertSame('POST', $mock->requests()[0]->getMethod());
        self::assertStringEndsWith('/synastry/aspect-grid', (string) $mock->requests()[0]->getUri());
        self::assertSame(['aspects' => [['a' => 'sun', 'b' => 'moon']]], $result);
    }

    public function testSingleSegmentEndpointsExposeCompute(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['transits' => []]])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $result = $aw->transits()->compute(['date' => '1990-07-14']);
        self::assertStringEndsWith('/transits', (string) $mock->requests()[0]->getUri());
        self::assertSame(['transits' => []], $result);
    }

    public function testMultiSegmentMethodNamesCamelCased(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => ['ok' => true]])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->bazi()->dayMaster([]);
        self::assertStringEndsWith('/bazi/day-master', (string) $mock->requests()[0]->getUri());
    }

    public function testServiceMethodPassesPerCallHeaders(): void
    {
        $mock = new MockHttpClient([
            new Response(200, [], json_encode(['ok' => true, 'data' => []])),
        ]);
        $aw = new Astroway(['apiKey' => 'aw_test_x', 'httpClient' => $mock]);
        $aw->transits()->compute([], ['headers' => ['X-Trace-Id' => 'trace_abc']]);
        self::assertSame('trace_abc', $mock->requests()[0]->getHeaderLine('x-trace-id'));
    }
}
