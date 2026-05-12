# astroway/sdk

> Official PHP SDK for the [AstroWay API](https://api.astroway.info) — natal charts, synastry, transits, Vedic dashas, Tarot, Numerology, Human Design, AI horoscopes. Type-safe, retry-aware, PSR-18 compatible.

[![Latest Version](https://img.shields.io/packagist/v/astroway/sdk.svg?style=flat&color=blue)](https://packagist.org/packages/astroway/sdk)
[![PHP version](https://img.shields.io/packagist/php-v/astroway/sdk.svg)](https://packagist.org/packages/astroway/sdk)
[![license: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

700+ endpoints. Pure **PSR-18 / PSR-17** — bring your own HTTP client (Guzzle, Symfony, Buzz, …) or let auto-discovery pick one. Built-in retry on 408/409/429/5xx with exponential backoff. Stainless-style error hierarchy (`AuthenticationError` / `RateLimitError` / `BadRequestError` / …). PHP 8.1+.

---

## Install

```bash
composer require astroway/sdk
```

The SDK requires a PSR-18 HTTP client. If you don't already have one, the simplest path is:

```bash
composer require guzzlehttp/guzzle nyholm/psr7
```

Already using Symfony's HTTP client, Buzz, or another PSR-18 implementation? `php-http/discovery` (a transitive dep of this SDK) auto-finds it. Or pass it explicitly:

```php
$aw = new Astroway([
    'apiKey'        => getenv('ASTROWAY_API_KEY'),
    'httpClient'    => $myPsr18Client,    // any Psr\Http\Client\ClientInterface
    'requestFactory'=> $myRequestFactory, // optional Psr\Http\Message\RequestFactoryInterface
    'streamFactory' => $myStreamFactory,  // optional Psr\Http\Message\StreamFactoryInterface
]);
```

Get an API key at <https://api.astroway.info/dashboard/sign-up> — **10 000 credits/month free**, no card required. Each endpoint costs 5–500 credits depending on what it computes ([pricing](https://api.astroway.info/pricing/)).

---

## Quick start

```php
<?php

use Astroway\Astroway;

$aw = new Astroway(['apiKey' => getenv('ASTROWAY_API_KEY')]);

$chart = $aw->chart()->compute([
    'date' => '1990-07-14',
    'time' => '14:30:00',
    'timezoneOffset' => 3,
    'latitude' => 50.45,
    'longitude' => 30.52,
    'houseSystem' => 'P',
]);

$asc = $chart['angles']['asc'];
printf("ASC: %s %.2f°\n", $asc['sign'], $asc['degree']);
```

The SDK exposes **103 typed service namespaces / 623 methods** auto-generated from the OpenAPI spec — `$aw->synastry()->aspectGrid([...])`, `$aw->bazi()->dayMaster([...])`, `$aw->vedic()->dashasVimshottariMaha([...])`, etc. The `{ ok, data, error }` envelope is unwrapped for you. Service objects are memoized per Astroway instance.

Need a raw response or an endpoint not yet covered by services? `$aw->request('POST', $path, ['json' => $body])` and `$aw->post($path, body: …)` remain available as escape hatches.

---

## Common workflows

### Synastry

```php
$result = $aw->synastry()->compute([
    'chart1' => ['date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52],
    'chart2' => ['date' => '1992-03-22', 'time' => '09:15:00', 'timezoneOffset' => 2, 'latitude' => 48.85, 'longitude' => 2.35],
]);
echo "Score: {$result['compatibility']['score']}/100 ({$result['compatibility']['label']})\n";
```

### Transits to natal

```php
$transits = $aw->transits()->compute([
    'date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52,
    'targetDate' => '2027-01-01',
]);
```

### Vedic Vimshottari Mahadasha

```php
$dasha = $aw->vedic()->dashasVimshottariMaha([
    'date' => '1985-07-22', 'time' => '06:45:00', 'timezoneOffset' => 5.5,
    'latitude' => 19.07, 'longitude' => 72.87,
]);
```

### Tarot daily card

```php
$card = $aw->tarot()->riderWaiteDaily(['seed' => 42]);
```

### Human Design

```php
$hd = $aw->humanDesign()->compute([
    'date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52,
]);
echo "{$hd['type']} — {$hd['strategy']} — {$hd['authority']}\n";
```

---

## Error handling

The SDK throws typed subclasses of `Astroway\Errors\ApiError`. Catch order matters — most specific first:

```php
use Astroway\Errors\ApiError;
use Astroway\Errors\AuthenticationError;
use Astroway\Errors\BadRequestError;
use Astroway\Errors\RateLimitError;

try {
    $aw->post('/chart', body: $body);
} catch (RateLimitError $e) {
    sleep($e->retryAfterSeconds ?? 60);
    // retry once...
} catch (AuthenticationError $e) {
    throw new RuntimeException('Rotate your AstroWay API key');
} catch (BadRequestError $e) {
    fwrite(STDERR, 'Validation failed: ' . json_encode($e->body) . PHP_EOL);
} catch (ApiError $e) {
    fwrite(STDERR, sprintf("API error %d (%s): %s [request_id=%s]\n", $e->status, $e->errorCode, $e->getMessage(), $e->requestId));
}
```

Full hierarchy under `Astroway\Errors`:

- `ApiError` (extends `\RuntimeException`)
  - `APIConnectionError`
    - `APITimeoutError`
  - `BadRequestError` (400)
  - `AuthenticationError` (401)
  - `PermissionDeniedError` (403)
  - `NotFoundError` (404)
  - `UnprocessableEntityError` (422)
  - `RateLimitError` (429) — carries `retryAfterSeconds`
  - `InternalServerError` (5xx)

> `errorCode` (not `code`) is the AstroWay-specific error code property. The base `\Exception::$code` would conflict.

---

## Configuration

```php
$aw = new Astroway([
    'apiKey'         => 'aw_live_...',               // required
    'baseUrl'        => 'https://api.astroway.info/v1', // override for staging / self-hosted
    'authScheme'     => 'header',                    // 'header' (X-Api-Key, default) or 'bearer' (Authorization: Bearer)
    'retry'          => [
        'maxRetries'        => 2,                    // total attempts = 1 + maxRetries
        'baseDelayMs'       => 250,
        'maxDelayMs'        => 30_000,
        'retryableStatuses' => [408, 409, 429, 500, 502, 503, 504],
    ],
    'defaultHeaders' => ['X-Trace-Id' => '...'],
    'httpClient'     => null,    // any PSR-18 ClientInterface (auto-discovered if omitted)
    'requestFactory' => null,    // any PSR-17 RequestFactoryInterface (auto-discovered)
    'streamFactory'  => null,    // any PSR-17 StreamFactoryInterface (auto-discovered)
]);
```

Default retry honors `Retry-After` (seconds or HTTP-date) on 429 responses.

Set `retry: ['maxRetries' => 0]` to disable retries entirely.

---

## Authentication

Two equivalent auth schemes — pick whichever your stack prefers:

- **Header (default):** `X-Api-Key: aw_live_...` — same convention as `curl`/Postman examples.
- **Bearer:** `Authorization: Bearer aw_live_...` — same convention as Stripe/OpenAI/Anthropic SDKs.

Set via `'authScheme' => 'bearer'` in the constructor options.

---

## Privacy

The SDK does **not** phone home. There is no telemetry, no analytics, no usage reporting. The only network traffic the SDK originates is the AstroWay API calls you ask it to make.

Outgoing requests carry two identifying headers so the AstroWay backend can distinguish SDK traffic from raw HTTP traffic in its own logs:

- `User-Agent: astroway-sdk-php/<version> (PHP/<php-version>; <os>)`
- `X-Astroway-Channel: sdk-php`

Neither carries a session ID, machine fingerprint, or anything personal.

---

## Stability

Since **`1.0.0` (2026-05-11)** this package follows strict SemVer:

- **Public `Astroway` surface stable inside `1.x`** — constructor `$options` shape, `request/get/post/put/delete/concurrent` methods, 100+ namespace accessors (`chart()`, `synastry()`, ...), `ApiError` public properties (`status`/`errorCode`/`requestId`/`creditsRemaining`/`retryAfterSeconds`/`body`). Removing or renaming any of them requires `2.0.0` with deprecation period.
- **Error subclass tree stable inside `1.x`** — 9 classified `*Error` subtypes are part of the contract.
- **Body shape stable inside `1.minor`.** Tightening (constraints, enums) ships in patches; new required keys require a minor bump.
- **API version vs SDK version are independent.** SDK `1.x` follows its own semver; the API itself sits at `/v1/`.
- **PHP 8.2+ required** since `1.0.0`. Need 8.1? Stay on `0.x` (will receive critical security patches).

### Migration from `0.1.0-alpha.x` / `0.1.0-beta.x` / `0.1.0-rc.1` to `0.1.0`

`0.1.0` freezes the public surface. **No breaking changes** vs `0.1.0-rc.1` — every export, namespace, error class, and option added across alphas / betas / RC ships unchanged. The freeze means future `0.1.x` patches will not narrow types, remove classes, or rename methods; that level of change requires a `0.2.0` minor bump.

| Coming from | Action |
|---|---|
| `0.1.0-alpha.1` (hard Guzzle dependency) | Now PSR-18 — bring your own client (`'httpClient' => $client`) or rely on auto-discovery via `php-http/discovery`. |
| `0.1.0-alpha.2` … `alpha.4` (no service classes / DTOs) | Switch to typed services (`$aw->chart()->compute($body)`, etc.) and DTO classes (`new NatalChartRequest(...)`). The escape hatch (`$aw->post('/chart', $body)`) still works. |
| `0.1.0-alpha.5` … `alpha.6` (no error refinement / idempotency) | Catch `RateLimitException` / `QuotaExceededException` separately; `$exception->requestId` / `creditsRemaining` / `retryAfterSeconds` available. Auto `Idempotency-Key` on POSTs. |
| `0.1.0-beta.1` … `beta.3` (no helpers / cache / concurrent) | `BirthDateTime::fromCity(...)` helpers; PSR-16 cache via `'cache' => $cache`; `$aw->concurrent(10)->all([...])` for parallel batches. |
| `0.1.0-beta.4` (no mock client) | `use Astroway\Testing\MockAstroway;` for PHPUnit — drop-in for `Astroway`. |
| `0.1.0-rc.1` (no logger / metrics) | Optional: pass `'logger' => $monolog` and `'metrics' => fn($e) => ...` for PSR-3 + observability. |

A surface-lock test suite (`tests/SurfaceLockTest.php`) uses Reflection to assert public method signatures, error subclass tree, and namespace accessor presence — any future PR that breaks the public surface fails CI before reaching Packagist. `phpstan analyse` is also clean at level 6.

---

## Links

- 📦 Packagist: <https://packagist.org/packages/astroway/sdk>
- 📘 API docs: <https://api.astroway.info/docs/api/>
- 🔑 Sign up & dashboard: <https://api.astroway.info/dashboard/>
- 💰 Pricing: <https://api.astroway.info/pricing/>
- 🟦 TypeScript SDK: [`@astroway/sdk`](https://www.npmjs.com/package/@astroway/sdk)
- 🐍 Python SDK: [`astroway`](https://pypi.org/project/astroway/)
- 🤖 MCP server: [`@astroway/mcp`](https://www.npmjs.com/package/@astroway/mcp)
- 🌐 Website: <https://astroway.info>

---

## License

MIT — see [LICENSE](LICENSE).
