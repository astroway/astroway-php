<?php

declare(strict_types=1);

namespace Astroway\Errors;

/** HTTP 5xx — server-side failure. Retried by default unless retry={"maxRetries": 0}. */
class InternalServerError extends ApiError
{
}
