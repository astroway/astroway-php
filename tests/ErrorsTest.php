<?php

declare(strict_types=1);

namespace Astroway\Tests;

use Astroway\Errors\APIConnectionError;
use Astroway\Errors\APITimeoutError;
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\BadRequestError;
use Astroway\Errors\Classify;
use Astroway\Errors\InternalServerError;
use Astroway\Errors\NotFoundError;
use Astroway\Errors\PermissionDeniedError;
use Astroway\Errors\RateLimitError;
use Astroway\Errors\UnprocessableEntityError;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    public function testAllSubclassesExtendApiError(): void
    {
        $cases = [
            new APIConnectionError('x'),
            new APITimeoutError('x'),
            new BadRequestError('x'),
            new AuthenticationError('x'),
            new PermissionDeniedError('x'),
            new NotFoundError('x'),
            new UnprocessableEntityError('x'),
            new RateLimitError('x'),
            new InternalServerError('x'),
        ];
        foreach ($cases as $err) {
            self::assertInstanceOf(ApiError::class, $err);
        }
    }

    public function testTimeoutExtendsConnection(): void
    {
        self::assertInstanceOf(APIConnectionError::class, new APITimeoutError('x'));
    }

    public function testAttributesArePreserved(): void
    {
        $err = new BadRequestError('bad', 400, 'INVALID', ['x' => 1], 'req_123');
        self::assertSame(400, $err->status);
        self::assertSame('INVALID', $err->errorCode);
        self::assertSame(['x' => 1], $err->body);
        self::assertSame('req_123', $err->requestId);
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $err = new RateLimitError('slow', 429, null, null, null, 30);
        self::assertSame(30, $err->retryAfterSeconds);
    }

    /**
     * @return iterable<string, array{int, class-string<ApiError>}>
     */
    public static function statusCases(): iterable
    {
        yield '400' => [400, BadRequestError::class];
        yield '401' => [401, AuthenticationError::class];
        yield '403' => [403, PermissionDeniedError::class];
        yield '404' => [404, NotFoundError::class];
        yield '422' => [422, UnprocessableEntityError::class];
        yield '429' => [429, RateLimitError::class];
        yield '500' => [500, InternalServerError::class];
        yield '502' => [502, InternalServerError::class];
        yield '503' => [503, InternalServerError::class];
        yield '504' => [504, InternalServerError::class];
    }

    /**
     * @dataProvider statusCases
     *
     * @param class-string<ApiError> $expected
     */
    public function testClassifyStatus(int $status, string $expected): void
    {
        $err = Classify::fromStatus($status, "{$status}");
        self::assertInstanceOf($expected, $err);
        self::assertSame($status, $err->status);
    }

    public function testClassify429WithRetryAfter(): void
    {
        $err = Classify::fromStatus(429, 'slow', null, null, null, 60);
        self::assertInstanceOf(RateLimitError::class, $err);
        self::assertSame(60, $err->retryAfterSeconds);
    }

    public function testClassifyUnknown4xxFallsBackToApiError(): void
    {
        $err = Classify::fromStatus(418, 'teapot');
        self::assertInstanceOf(ApiError::class, $err);
        self::assertNotInstanceOf(BadRequestError::class, $err);
        self::assertNotInstanceOf(InternalServerError::class, $err);
    }
}
