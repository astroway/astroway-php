# astroway/sdk

> Official PHP SDK for the [AstroWay API](https://api.astroway.info) — natal charts, synastry, transits, Vedic dashas, Tarot, Numerology, Human Design, AI horoscopes. Type-safe, retry-aware, PSR-18 compatible.

[![Latest Version](https://img.shields.io/packagist/v/astroway/sdk.svg?style=flat&color=blue)](https://packagist.org/packages/astroway/sdk)
[![PHP version](https://img.shields.io/packagist/php-v/astroway/sdk.svg)](https://packagist.org/packages/astroway/sdk)
[![license: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

700+ endpoints. Built on Guzzle 7 + PSR-18 / PSR-7. Built-in retry on 408/409/429/5xx with exponential backoff. Stainless-style error hierarchy (`AuthenticationError` / `RateLimitError` / `BadRequestError` / …). PHP 8.1+.

---

## Install

```bash
composer require astroway/sdk
```

Get an API key at <https://api.astroway.info/dashboard/sign-up> — **10 000 credits/month free**, no card required. Each endpoint costs 5–500 credits depending on what it computes ([pricing](https://api.astroway.info/pricing/)).

---

## Quick start

```php
<?php

use Astroway\Astroway;

$aw = new Astroway(['apiKey' => getenv('ASTROWAY_API_KEY')]);

$chart = $aw->post('/chart', body: [
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

---

## Common workflows

### Synastry

```php
$result = $aw->post('/synastry', body: [
    'chart1' => ['date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52],
    'chart2' => ['date' => '1992-03-22', 'time' => '09:15:00', 'timezoneOffset' => 2, 'latitude' => 48.85, 'longitude' => 2.35],
]);
echo "Score: {$result['compatibility']['score']}/100 ({$result['compatibility']['label']})\n";
```

### Transits to natal

```php
$transits = $aw->post('/transits', body: [
    'date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3, 'latitude' => 50.45, 'longitude' => 30.52,
    'targetDate' => '2027-01-01',
]);
```

### Vedic Vimshottari Mahadasha

```php
$dasha = $aw->post('/vedic/dashas/vimshottari/maha', body: [
    'date' => '1985-07-22', 'time' => '06:45:00', 'timezoneOffset' => 5.5,
    'latitude' => 19.07, 'longitude' => 72.87,
]);
```

### Tarot reading

```php
$spread = $aw->post('/tarot/rider-waite/spread', body: ['spreadType' => 'three-card', 'seed' => 42]);
```

### Human Design

```php
$hd = $aw->post('/human-design', body: [
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
    'apiKey'      => 'aw_live_...',                // required
    'baseUrl'     => 'https://api.astroway.info/v1', // override for staging / self-hosted
    'authScheme'  => 'header',                      // 'header' (X-Api-Key, default) or 'bearer' (Authorization: Bearer)
    'timeout'     => 30.0,                          // per-request timeout in seconds
    'retry'       => [
        'maxRetries'        => 2,                   // total attempts = 1 + maxRetries
        'baseDelayMs'       => 250,
        'maxDelayMs'        => 30_000,
        'retryableStatuses' => [408, 409, 429, 500, 502, 503, 504],
    ],
    'defaultHeaders' => ['X-Trace-Id' => '...'],
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

- **Public API stable inside a major version.** Methods/classes shipped under `1.x` won't be renamed or removed without a deprecation note in `CHANGELOG.md` and a one-minor parallel-availability window.
- **Body shape stable inside a minor version.** Tightening (constraints, enums) ships in patches; new required keys require a minor bump.
- **API version vs SDK version are independent.** SDK `0.x` follows its own semver; the API itself sits at `/v1/`.

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
