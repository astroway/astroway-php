<?php

declare(strict_types=1);

namespace Astroway\Errors;

use Throwable;

/** HTTP 429 — rate limit exceeded. retryAfterSeconds taken from Retry-After when present. */
class RateLimitError extends ApiError
{
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $body, $requestId, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}
