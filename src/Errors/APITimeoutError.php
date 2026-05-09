<?php

declare(strict_types=1);

namespace Astroway\Errors;

/** Request exceeded the configured timeout. */
class APITimeoutError extends APIConnectionError
{
}
