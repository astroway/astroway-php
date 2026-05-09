# Changelog

## 0.1.0-alpha.1 — 2026-05-09

Initial alpha release. Public API may shift before `0.1.0` proper based on integrator feedback.

### What's in the box

- **`Astroway` client class** built on Guzzle 7 (PSR-18 compatible). Sync only — PHP doesn't have a unified async story to mirror our TS / Python SDKs.
- **Two auth schemes:** `X-Api-Key` (default, matches curl/Postman) or `Authorization: Bearer` (matches Stripe/OpenAI/Anthropic convention) via `'authScheme' => 'bearer'`.
- **Stainless-template error hierarchy:** `ApiError` → `BadRequestError` / `AuthenticationError` / `PermissionDeniedError` / `NotFoundError` / `UnprocessableEntityError` / `RateLimitError` / `InternalServerError` / `APIConnectionError` (→ `APITimeoutError`).
- **Built-in retry** via Guzzle middleware with exponential backoff + full jitter on 408 / 409 / 429 / 5xx and connection errors. Default 2 retries; configurable via `'retry' => ['maxRetries' => 0]` to disable. Honors `Retry-After` (seconds or HTTP-date) on 429.
- **Per-request timeout** via Guzzle's `timeout` + `connect_timeout` options, default 30s.
- **Identification headers** — `User-Agent: astroway-sdk-php/<version> (PHP/<php-version>; <os>)` and `X-Astroway-Channel: sdk-php`. **No telemetry, no phone-home.**
- **Auto-unwrap of `{ ok, data, error }` envelope** — methods return the `data` payload directly so user code reads naturally.
- **`errorCode` property** (not `code`) on `ApiError` to avoid clash with `\Exception::$code`.
- **26 PHPUnit tests** covering error classification, retry semantics, header propagation, auth scheme switching, response unwrap, request-id capture.

### Stack

- PHP 8.1+ (with constructor promotion + readonly properties + match expressions).
- `guzzlehttp/guzzle ^7.8` — HTTP client (PSR-18 compatible).
- `psr/http-client`, `psr/http-message` — interface contracts.
- PHPUnit 10 + Guzzle MockHandler for tests.

### Internal

- PSR-4 autoload (`Astroway\` → `src/`).
- Distributed via Packagist; auto-mirror from GitHub releases.
- Tag-driven release pipeline mirrors `@astroway/sdk` and `astroway` (Python).
