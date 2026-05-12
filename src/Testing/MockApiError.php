<?php

declare(strict_types=1);

namespace Astroway\Testing;

use Astroway\Errors\ApiError;
use Astroway\Errors\Classify;

/**
 * Factory for mock-thrown ApiError subclasses, classified the same way real
 * server responses are. Use as a fixture in `MockAstroway::respond()` so your
 * retry / error-handling code paths see the right concrete subclass.
 *
 *     $mock->respond('POST', '/chart',
 *         MockApiError::make(status: 401, code: 'INVALID_API_KEY')
 *     );
 *     // → throws \Astroway\Errors\AuthenticationError on next ->charts()->natal()
 */
final class MockApiError
{
    /**
     * @return callable(array<string, mixed>): never
     */
    public static function make(
        int $status,
        ?string $code = null,
        ?string $message = null,
        ?int $retryAfterSeconds = null,
        ?int $creditsRemaining = null,
        ?string $requestId = null,
    ): callable {
        return static function () use ($status, $code, $message, $retryAfterSeconds, $creditsRemaining, $requestId): never {
            throw self::error($status, $code, $message, $retryAfterSeconds, $creditsRemaining, $requestId);
        };
    }

    public static function error(
        int $status,
        ?string $code = null,
        ?string $message = null,
        ?int $retryAfterSeconds = null,
        ?int $creditsRemaining = null,
        ?string $requestId = null,
    ): ApiError {
        return Classify::fromStatus(
            status: $status,
            message: $message ?? sprintf('Mock %d %s', $status, $code ?? ''),
            errorCode: $code,
            body: null,
            requestId: $requestId,
            retryAfterSeconds: $retryAfterSeconds,
            creditsRemaining: $creditsRemaining,
        );
    }
}
