<?php

declare(strict_types=1);

namespace Astroway\Errors;

/**
 * Account ran out of credits / quota for the current period.
 *
 * HTTP 402 or ``code: OUT_OF_CREDITS`` / ``QUOTA_EXCEEDED`` /
 * ``CREDIT_LIMIT_REACHED``. Distinct from :class:`RateLimitError` — backing off
 * won't help; you need to top up the account or wait until the period resets.
 */
class QuotaExceededError extends ApiError
{
}
