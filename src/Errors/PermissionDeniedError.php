<?php

declare(strict_types=1);

namespace Astroway\Errors;

/** HTTP 403 — authenticated but not allowed to call this endpoint. */
class PermissionDeniedError extends ApiError
{
}
