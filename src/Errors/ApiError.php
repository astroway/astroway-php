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
 *     } catch (AuthenticationError $e) {
 *         // rotate key
 *     } catch (ApiError $e) {
 *         // generic 4xx/5xx, inspect $e->status / $e->errorCode / $e->body / $e->requestId
 *     } catch (\Throwable $e) {
 *         throw $e;
 *     }
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

    public function __construct(
        string $message,
        ?int $status = null,
        ?string $errorCode = null,
        mixed $body = null,
        ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
        $this->requestId = $requestId;
    }
}
