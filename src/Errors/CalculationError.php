<?php

declare(strict_types=1);

namespace Astroway\Errors;

/**
 * Server-side calculation failure for an otherwise-valid request.
 *
 * Typically means a Swiss Ephemeris boundary, missing dataset, or unsupported
 * house system for high latitudes. ``code: CALCULATION_ERROR`` /
 * ``EPHEMERIS_ERROR``.
 */
class CalculationError extends ApiError
{
}
