<?php

declare(strict_types=1);

namespace Astroway\Errors;

final class Classify
{
    /**
     * Maps an HTTP status to the most specific subclass of ApiError.
     */
    public static function fromStatus(
        int $status,
        string $message,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?int $retryAfterSeconds = null,
    ): ApiError {
        return match (true) {
            $status === 400 => new BadRequestError($message, $status, $errorCode, $body, $requestId),
            $status === 401 => new AuthenticationError($message, $status, $errorCode, $body, $requestId),
            $status === 403 => new PermissionDeniedError($message, $status, $errorCode, $body, $requestId),
            $status === 404 => new NotFoundError($message, $status, $errorCode, $body, $requestId),
            $status === 422 => new UnprocessableEntityError($message, $status, $errorCode, $body, $requestId),
            $status === 429 => new RateLimitError(
                $message, $status, $errorCode, $body, $requestId, $retryAfterSeconds,
            ),
            $status >= 500 => new InternalServerError($message, $status, $errorCode, $body, $requestId),
            default => new ApiError($message, $status, $errorCode, $body, $requestId),
        };
    }
}
