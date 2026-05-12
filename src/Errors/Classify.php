<?php

declare(strict_types=1);

namespace Astroway\Errors;

final class Classify
{
    private const QUOTA_CODES = ['OUT_OF_CREDITS', 'QUOTA_EXCEEDED', 'CREDIT_LIMIT_REACHED'];
    private const CALCULATION_CODES = ['CALCULATION_ERROR', 'EPHEMERIS_ERROR'];

    /**
     * Maps an HTTP status (and optional server error code) to the most specific subclass of ApiError.
     */
    public static function fromStatus(
        int $status,
        string $message,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?int $retryAfterSeconds = null,
        ?int $creditsRemaining = null,
    ): ApiError {
        // Code-first dispatch — quota / calculation errors may ride on multiple HTTP statuses.
        if ($errorCode !== null) {
            if (in_array($errorCode, self::QUOTA_CODES, true)) {
                return new QuotaExceededError(
                    $message, $status, $errorCode, $body, $requestId, null,
                    $creditsRemaining, $retryAfterSeconds,
                );
            }
            if (in_array($errorCode, self::CALCULATION_CODES, true)) {
                return new CalculationError(
                    $message, $status, $errorCode, $body, $requestId, null,
                    $creditsRemaining, $retryAfterSeconds,
                );
            }
        }

        return match (true) {
            $status === 400 => new BadRequestError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 401 => new AuthenticationError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 402 => new QuotaExceededError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 403 => new PermissionDeniedError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 404 => new NotFoundError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 422 => new UnprocessableEntityError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            $status === 429 => new RateLimitError(
                $message, $status, $errorCode, $body, $requestId, $retryAfterSeconds,
                null, $creditsRemaining,
            ),
            $status >= 500 => new InternalServerError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
            default => new ApiError(
                $message, $status, $errorCode, $body, $requestId, null,
                $creditsRemaining, $retryAfterSeconds,
            ),
        };
    }
}
