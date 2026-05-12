<?php

declare(strict_types=1);

namespace Astroway\Errors;

use RuntimeException;
use Throwable;

/**
 * Base exception for every error raised by the SDK.
 *
 * Catch order in user code:
 *
 *     try { ... } catch (RateLimitError $e) {
 *         sleep($e->retryAfterSeconds ?? 60);
 *     } catch (QuotaExceededError $e) {
 *         topUpAndAlert($e->creditsRemaining); // often 0
 *     } catch (AuthenticationError $e) {
 *         // rotate key
 *     } catch (CalculationError $e) {
 *         // ephemeris boundary / unsupported house system — inspect $e->body
 *     } catch (ApiError $e) {
 *         // generic 4xx/5xx, inspect $e->status / $e->errorCode / $e->body / $e->requestId
 *     } catch (\Throwable $e) {
 *         throw $e;
 *     }
 *
 * Every ApiError carries `requestId`, `creditsRemaining`, and `retryAfterSeconds`
 * so user code can build support tickets and debug uniformly.
 */
class ApiError extends RuntimeException
{
    /** HTTP status code, or null for connection / timeout. */
    public readonly ?int $status;

    /** Server-provided error code (e.g. INVALID_KEY, OUT_OF_CREDITS). */
    public readonly ?string $errorCode;

    /** Raw response body (parsed if JSON, raw string otherwise, null when unavailable). */
    public readonly mixed $body;

    /** AstroWay request ID, when present in X-Request-Id response header. */
    public readonly ?string $requestId;

    /** Credits remaining on the caller's account, surfaced from X-Credits-Remaining. */
    public readonly ?int $creditsRemaining;

    /** Seconds to wait before retrying — set on 429 and quota-exceeded responses. */
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
        ?int $creditsRemaining = null,
        ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
        $this->requestId = $requestId;
        $this->creditsRemaining = $creditsRemaining;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}
