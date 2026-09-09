<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Dto\BirthData;
use PHPUnit\Framework\TestCase;

/**
 * What the 1.6.0 spec resync brought, pinned so a later resync cannot drop it.
 *
 * The bundled snapshot was frozen at api-calc 2.105.0 and is now 2.152.1: 24 new
 * paths, 74 new components, 22 new methods, nothing removed or renamed. The
 * TypeScript and Python generators report the same 112 namespaces and 709
 * methods, which is the cheapest cross-check that the three still agree.
 */
final class SpecSnapshotTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(__DIR__.'/../openapi.json'), true);

        return $spec;
    }

    public function testMethodsAddedByTheResyncExist(): void
    {
        $aw = new Astroway(['apiKey' => 'aw_test_x']);
        foreach ([
            ['vedic', 'gemstones'],
            ['vedic', 'varshaphal'],
            ['vedic', 'bhavabala'],
            ['kabbalah', 'gematria'],
            ['parans', 'star'],
            ['reports', 'relocation'],
            ['reports', 'gemstone'],
            ['chinese', 'solarTerms'],
            ['chinese', 'fengShuiFlyingStar'],
            ['wellness', 'biorhythm'],
            ['ziwei', 'fourTransformations'],
            ['acg', 'bestPlaces'],
            ['agent', 'toolsGet'],
        ] as [$ns, $method]) {
            self::assertTrue(
                method_exists($aw->{$ns}(), $method),
                "{$ns}()->{$method}() is missing"
            );
        }
    }

    public function testHtmlWidgetsAndKeylessMirrorStayOut(): void
    {
        // /embed/* answers text/html and /public/* mirrors keyed endpoints. The
        // explicit /embed/ skip went out with this resync: the content-type
        // filter is enough now that every widget path declares text/html.
        $spec = $this->spec();
        $embed = array_filter(array_keys($spec['paths']), static fn (string $p): bool => str_starts_with($p, '/embed/'));
        self::assertCount(14, $embed);
        foreach ($embed as $path) {
            $op = $spec['paths'][$path]['post'] ?? $spec['paths'][$path]['get'];
            self::assertArrayNotHasKey('application/json', $op['responses']['200']['content'], $path);
        }
        self::assertFalse(method_exists(new Astroway(['apiKey' => 'aw_test_x']), 'embed'));
    }

    public function testBirthDataWillNotSendAChartWithNoPlace(): void
    {
        // The DTO defaulted latitude and longitude to 0, so a caller who omitted
        // them sent a real request for 0N 0E rather than triggering the server's
        // deprecation path. api-calc stopped defaulting them in 2.141.0.
        $this->expectException(\InvalidArgumentException::class);
        new BirthData(date: '1990-07-14', time: '14:30:00');
    }

    public function testSpecCarriesTypedRequestBodies(): void
    {
        // 246 paths published a bare {"type": "object"} body until api-calc
        // 2.152.0. PHP cannot enforce them at compile time, but the generated
        // docblocks and the 400 a caller sees both come from here.
        $untyped = [];
        foreach ($this->spec()['paths'] as $path => $item) {
            $op = $item['post'] ?? $item['put'] ?? $item['patch'] ?? null;
            $schema = $op['requestBody']['content']['application/json']['schema'] ?? null;
            if (is_array($schema) && [] === array_intersect(['$ref', 'properties', 'allOf', 'oneOf'], array_keys($schema))) {
                $untyped[] = $path;
            }
        }
        self::assertSame([], $untyped);
    }
}
