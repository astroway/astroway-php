<?php

declare(strict_types=1);

namespace Astroway\Errors;

use Throwable;

/**
 * HTTP 429 — rate limit exceeded. ``$retryAfterSeconds`` (inherited from
 * :class:`ApiError`) carries the ``Retry-After`` value when present.
 *
 * Distinct from :class:`QuotaExceededError`: rate-limit means short-window
 * throttling (back off and try again), while quota-exceeded means you ran out
 * of credits and need to top up or wait for the period to reset.
 */
class RateLimitError extends ApiError
{
    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
        ?int $creditsRemaining = null,
    ) {
        parent::__construct(
            $message, $status, $errorCode, $body, $requestId, $previous,
            $creditsRemaining, $retryAfterSeconds,
        );
    }
}
