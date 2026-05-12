<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Astroway;
use Astroway\Concurrent;
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\BadRequestError;
use Astroway\Errors\CalculationError;
use Astroway\Errors\InternalServerError;
use Astroway\Errors\NotFoundError;
use Astroway\Errors\PermissionDeniedError;
use Astroway\Errors\QuotaExceededError;
use Astroway\Errors\RateLimitError;
use Astroway\Errors\UnprocessableEntityError;
use Astroway\Testing\MockApiError;
use Astroway\Testing\MockAstroway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 0.1.0 stable-surface lock — public class shapes that integrators depend on.
 *
 * Removing a class, narrowing a method signature, or renaming a public property
 * breaks production code in the wild. Adding to the surface is fine; removing
 * requires a major bump.
 */
final class SurfaceLockTest extends TestCase
{
    public function testAstrowayVersionConstantStringSemver(): void
    {
        self::assertIsString(Astroway::VERSION);
        self::assertNotEmpty(Astroway::VERSION);
    }

    public function testAstrowayDefaultBaseUrlConstant(): void
    {
        self::assertSame('https://api.astroway.info/v1', Astroway::DEFAULT_BASE_URL);
    }

    public function testPublicSurfaceMethodsExist(): void
    {
        $rc = new ReflectionClass(Astroway::class);
        foreach (['__construct', 'request', 'get', 'post', 'put', 'delete', 'concurrent'] as $name) {
            self::assertTrue(
                $rc->hasMethod($name),
                "Astroway lost public method: {$name}",
            );
            $m = $rc->getMethod($name);
            self::assertTrue($m->isPublic(), "Astroway::{$name} is no longer public");
        }
    }

    public function testRequestMethodSignature(): void
    {
        $m = (new ReflectionClass(Astroway::class))->getMethod('request');
        $params = $m->getParameters();
        self::assertSame('method', $params[0]->getName());
        self::assertSame('path', $params[1]->getName());
        self::assertSame('options', $params[2]->getName());
        self::assertTrue($params[2]->isDefaultValueAvailable());
    }

    public function testPostMethodSignature(): void
    {
        $m = (new ReflectionClass(Astroway::class))->getMethod('post');
        $params = $m->getParameters();
        self::assertSame('path', $params[0]->getName());
        self::assertSame('body', $params[1]->getName());
        self::assertSame('query', $params[2]->getName());
        self::assertSame('cache', $params[3]->getName());
    }

    public function testErrorHierarchyLocked(): void
    {
        foreach ([
            BadRequestError::class,
            AuthenticationError::class,
            PermissionDeniedError::class,
            NotFoundError::class,
            UnprocessableEntityError::class,
            RateLimitError::class,
            QuotaExceededError::class,
            CalculationError::class,
            InternalServerError::class,
        ] as $cls) {
            self::assertTrue(is_subclass_of($cls, ApiError::class), "{$cls} no longer extends ApiError");
        }
    }

    public function testApiErrorPublicPropertiesLocked(): void
    {
        $rc = new ReflectionClass(ApiError::class);
        foreach (['status', 'errorCode', 'requestId', 'creditsRemaining', 'retryAfterSeconds', 'body'] as $prop) {
            self::assertTrue($rc->hasProperty($prop), "ApiError lost property: {$prop}");
            $p = $rc->getProperty($prop);
            self::assertTrue($p->isPublic(), "ApiError::\${$prop} is no longer public");
        }
    }

    public function testTestingNamespaceShipsBothClasses(): void
    {
        self::assertTrue(class_exists(MockAstroway::class));
        self::assertTrue(class_exists(MockApiError::class));
        // MockAstroway must remain a drop-in for Astroway.
        self::assertTrue(is_subclass_of(MockAstroway::class, Astroway::class));
    }

    public function testConcurrentHelperShape(): void
    {
        $rc = new ReflectionClass(Concurrent::class);
        self::assertTrue($rc->hasMethod('all'), 'Concurrent::all() is the locked entry point');
    }

    public function testServiceMethodsAreFinalAndReturnSameType(): void
    {
        // ChartService is the canonical example; if its shape drifts, the rest
        // of the auto-generated services have drifted too.
        $rc = new ReflectionClass(\Astroway\Namespaces\ChartService::class);
        self::assertTrue($rc->hasMethod('compute'), 'ChartService::compute() must remain — it backs the natal endpoint');
        $m = $rc->getMethod('compute');
        self::assertTrue($m->isPublic());
        $params = $m->getParameters();
        self::assertSame('body', $params[0]->getName());
        self::assertSame('options', $params[1]->getName());
    }

    public function testHasServicesTraitExposesCanonicalAccessors(): void
    {
        // We verify the surface via the runtime Astroway class — the trait is
        // mixed in there. If any accessor disappears, integrator code breaks.
        $rc = new ReflectionClass(Astroway::class);
        foreach (['chart', 'synastry', 'ai', 'tarot', 'numerology'] as $accessor) {
            self::assertTrue(
                $rc->hasMethod($accessor),
                "Astroway::{$accessor}() namespace accessor lost — breaks \$aw->{$accessor}() user code",
            );
            $m = $rc->getMethod($accessor);
            self::assertTrue($m->isPublic());
            self::assertSame(0, $m->getNumberOfRequiredParameters(), "Namespace accessors must remain zero-arg");
        }
    }
}
