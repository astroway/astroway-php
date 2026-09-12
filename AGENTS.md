# AGENTS.md for `astroway/sdk` (PHP)

Instructions for AI coding agents writing PHP against the AstroWay API through
this package. Written to be executed, not summarised.

This file ships **inside the Composer package**, so after
`composer require astroway/sdk` it is already on disk at
`vendor/astroway/sdk/AGENTS.md`. Point your agent at it, or add that path to
`CLAUDE.md` / `.cursor/rules/`.

Full language-agnostic playbook, always current:
<https://api.astroway.info/AGENTS.md>
Endpoint catalogue: <https://api.astroway.info/llms.txt>
OpenAPI 3.1: <https://api.astroway.info/v1/openapi.json>

## Rule 0: the API does not geocode

There is no endpoint that turns a city name into coordinates. Resolve the place
on your side and pass numbers. Every chart call needs `latitude`, `longitude`
and `timezoneOffset`, and getting the offset wrong moves the houses, not just
the clock.

## Install and construct

```bash
composer require astroway/sdk
```

The SDK is **PSR-18 / PSR-17** and brings no HTTP client of its own. If the
project has none:

```bash
composer require guzzlehttp/guzzle nyholm/psr7
```

`php-http/discovery` then finds it. To be explicit:

```php
$aw = new Astroway([
    'apiKey'     => getenv('ASTROWAY_API_KEY'),
    'httpClient' => $myPsr18Client,
]);
```

Normal use:

```php
<?php
use Astroway\Astroway;

$aw = new Astroway(['apiKey' => getenv('ASTROWAY_API_KEY')]);
```

PHP 8.1+. Never inline the key.

## Try it with no key at all before writing auth code

Nine endpoints answer without an account:

```bash
curl https://api.astroway.info/v1/public/moon-phase
curl -X POST https://api.astroway.info/v1/public/chart \
  -H 'Content-Type: application/json' \
  -d '{"date":"1990-07-14","time":"14:30:00","latitude":50.45,"longitude":30.52,"timezoneOffset":3}'
```

Public responses carry a `_footer` attribution string: strip it when parsing,
keep it when displaying.

## Calling an endpoint

Services are **methods that return a service object**, not properties. This is
the single most common mistake an agent makes here:

```php
$chart = $aw->chart()->compute([...]);            // correct
$chart = $aw->chart->compute([...]);              // wrong, chart is a method
```

Roughly 103 services and 623 methods, generated from the OpenAPI spec, named in
camelCase:

```php
$grid      = $aw->synastry()->aspectGrid([...]);
$dayMaster = $aw->bazi()->dayMaster([...]);
$maha      = $aw->vedic()->dashasVimshottariMaha([...]);
```

Service objects are memoized per `Astroway` instance, so calling `$aw->chart()`
repeatedly is free.

**Do not guess a method name.** If unsure, use the escape hatch, which takes any
path from the spec:

```php
$raw = $aw->request('POST', '/some/endpoint', ['json' => $body]);
```

## Results are arrays, not objects

```php
$asc = $chart['houses']['ascendant'];          // correct
$asc = $chart->houses->ascendant;              // wrong
```

The `{ ok, data, error }` envelope is unwrapped for you, so `$chart` is already
the `data` payload.

## The four things agents get wrong

1. **Body keys are the API's spelling, not PHP's.** `timezoneOffset`,
   `houseSystem`, `latitude`, `longitude`. `lat`, `lng`, `lon`, `tz`,
   `timezone_offset` and every other casing or separator variant return
   `400 INVALID_FIELD` naming the correct field. Nothing falls back silently.
2. **`time` is `HH:mm:ss`.** `'14:30'` returns `400 INVALID_INPUT`. Pad it.
3. **`timezoneOffset` is a number of hours from UTC**, `5.75` for Kathmandu,
   `-4` for New York in summer. A zone name like `'Europe/Kyiv'` is rejected. It
   is the offset **at the birth moment**, so historical DST matters.
4. **`/chart` returns positions, not labels.** `$chart['houses']['ascendant']`
   and every `$chart['planets'][$i]['longitude']` are ecliptic longitudes in
   degrees. The sign is `(int) ($longitude / 30)` into the twelve and the degree
   within it is `fmod($longitude, 30)`. There is no `sign` key on a planet; if
   you print one, you computed it.

Retry on 408/409/429/5xx with exponential backoff is built in and honours
`Retry-After`. Do not wrap calls in your own loop; it multiplies the spend.

## Sandbox and live

The key selects the environment, not the URL:

- `aw_live_…` spends credits.
- `aw_test_…` calls the same paths and spends nothing.

Switch the key, never the URL. Sandbox covers calculation. AI interpretation,
generated reports and rendering return `402 SANDBOX_ENDPOINT_UNAVAILABLE` on a
test key, because those cost real money per call.

## Errors

```php
use Astroway\Errors\BadRequestError;
use Astroway\Errors\RateLimitError;
use Astroway\Errors\AuthenticationError;

try {
    $chart = $aw->chart()->compute($body);
} catch (BadRequestError $e) {
    // the message names the field. Fix the body; do not retry.
} catch (RateLimitError $e) {
    // already retried internally; back off or raise the plan
} catch (AuthenticationError $e) {
    // key missing, revoked, or a sandbox key on a live-only endpoint
}
```

## Cost, before you loop

Endpoints cost 5 to 500 credits depending on what they compute. Free tier is
10 000 credits a month, no card. Before generating a loop, read the per-endpoint
cost from `GET /v1/public/endpoint-costs`, which needs no key, or
<https://api.astroway.info/pricing/>.

## What NOT to do

- Do not put a live key in anything rendered to a browser, including a Blade or
  Twig template that echoes it into JavaScript. There is no publishable key
  class yet.
- Do not invent endpoint paths. If it is not in `openapi.json`, it does not
  exist.
- Do not stitch a chart from several calls. Ask whether one endpoint already
  returns the whole thing; most do.
- Do not translate output yourself. Pass `lang` where supported; the API answers
  in 21 languages.
- Do not assume a sign order other than Aries first. Longitude zero is 0° Aries.
