# Changelog

## 1.0.0 — 2026-05-11

**Production guarantee.** Public API stable, SemVer commitment. Same code as `0.1.0` — same `Astroway` constructor, 100+ namespace services, 12-class error hierarchy, DTOs, helpers, PSR-16 cache, Guzzle promises concurrency, mock client, PSR-3 logger + metrics. Major bump signals the contract, not surface change.

### Changed

- **PHP 8.2 minimum.** Dropped PHP 8.1 — security support ended 2025-12-31. Composer constraint `^8.1` → `^8.2`.
  - Still on PHP 8.1? Pin to `astroway/sdk:^0.1.0` (will receive critical security patches).
- **SemVer commitment in README** — removing or narrowing the public `Astroway` surface requires `2.0.0` with deprecation period.

### Locked (same as 0.1.0, now contractual)

`Astroway::VERSION` + `DEFAULT_BASE_URL`. Constructor `$options` shape (`apiKey`, `baseUrl`, `authScheme`, `timeout`, `retry`, `defaultHeaders`, `httpClient`, `requestFactory`, `streamFactory`, `idempotency`, `cache`, `cacheTtlSeconds`, `logger`, `metrics`). `request/get/post/put/delete/concurrent` + 100+ namespace accessors. 9-subclass error tree. `ApiError` public properties (`status`/`errorCode`/`requestId`/`creditsRemaining`/`retryAfterSeconds`/`body`). `MockAstroway` IS-A `Astroway`. `phpstan` level 6 clean.

### Migration from 0.1.x

`composer require astroway/sdk:^1.0` is a drop-in upgrade **if you're on PHP 8.2+**.

### Verification

128 PHPUnit tests pass. `phpstan analyse` clean.

## 0.1.0 — 2026-05-11

**Stable surface commitment.** Public API frozen — every export shipped across alphas / betas / RC is now part of the `0.1.x` contract. No code changes vs `0.1.0-rc.1` — same `Astroway` constructor, 100+ namespace services, 12-class error hierarchy, DTOs, helpers, PSR-16 cache, Guzzle promises concurrency, mock client, PSR-3 logger + metrics surface. Ready to be depended on.

### Locked

- **Public method surface** on `Astroway` — `__construct`, `request`, `get`, `post`, `put`, `delete`, `concurrent`, plus all 100+ namespace accessors (`chart()`, `synastry()`, `ai()`, `tarot()`, `numerology()`, ...). Removing or renaming requires `1.0.0`.
- **Constructor `$options` shape** — `apiKey`, `baseUrl`, `authScheme`, `timeout`, `retry`, `defaultHeaders`, `httpClient`, `requestFactory`, `streamFactory`, `idempotency`, `cache`, `cacheTtlSeconds`, `logger`, `metrics`. Documented as a `phpstan` array shape on `__construct`.
- **`Astroway::VERSION` and `Astroway::DEFAULT_BASE_URL` constants** — public, documented, locked.
- **Error subclass tree** — 9 classified `*Error` subtypes (`BadRequest`, `Authentication`, `PermissionDenied`, `NotFound`, `UnprocessableEntity`, `RateLimit`, `QuotaExceeded`, `Calculation`, `InternalServer`) all extend `ApiError`. User code catches them — collapsing breaks production silently.
- **`ApiError` public properties** — `status`, `errorCode`, `requestId`, `creditsRemaining`, `retryAfterSeconds`, `body`. Renaming any breaks support-ticket flows.
- **`Astroway\Testing\MockAstroway`** stays a subclass of `Astroway` so it remains drop-in for the namespace surface.
- **Surface-lock test (`tests/SurfaceLockTest.php`)** uses Reflection to enforce the above. PRs that drift fail CI before Packagist.
- **`phpstan analyse`** runs on every push — clean at level 6 (auto-generated `Namespaces/*.php` excluded for now; tightening is a `1.0.0` task).

### Migration

`composer require astroway/sdk:^0.1.0` is a drop-in upgrade from any `0.1.0-rc.x`. README has a migration table covering each pre-release stage.

### Verification

118 PHPUnit tests pass (117 from rc.1 baseline + 11 new in `tests/SurfaceLockTest.php`). `phpstan analyse` clean.

## 0.1.0-rc.1 — 2026-05-10

First **release candidate**. PSR-3 logger integration + observability hooks. Production users want `Astroway` to participate in their existing logging stack (Monolog, Symfony's logger, Laravel's `Log` facade) and metrics pipeline (Prometheus, Datadog, StatsD) without re-implementing request tracing client-side.

### Added

- **`logger` constructor option** — pass any `Psr\Log\LoggerInterface`. The SDK emits a `debug` record on every outgoing request, then a level-by-status record on every response (`debug` for 2xx/3xx, `warning` for 4xx, `error` for 5xx) and `error` on PSR-18 exceptions. Each record's context carries:
  - `astroway_trace_id` — a per-request UUID4 hex correlator (8 bytes).
  - `method`, `path`, `idempotency_key`.
  - On responses: `status`, `latency_ms`, `request_id` (server-side `x-request-id`), `credits_remaining`.
  - On exceptions: the `exception` object (PSR-3 standard) + `latency_ms`.
  ```php
  use Monolog\Logger;
  use Monolog\Handler\StreamHandler;

  $log = new Logger('astroway');
  $log->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
  $aw = new Astroway(['apiKey' => $key, 'logger' => $log]);
  ```
- **`metrics` constructor option** — `callable(array $event): void` invoked alongside log records. `$event['event']` is `request` | `response` | `error` and the rest of the fields mirror the log context. Useful for incrementing Prometheus counters / StatsD timers without re-parsing logs:
  ```php
  $aw = new Astroway([
      'apiKey'  => $key,
      'logger'  => $log,
      'metrics' => fn(array $e) => $statsd->timing(
          "astroway.{$e['event']}",
          $e['latency_ms'] ?? 0,
          ['status' => $e['status'] ?? 'error'],
      ),
  ]);
  ```
  Metrics handler errors are swallowed — observability must never break the request path.
- **`X-Astroway-Trace-Id` header** automatically attached to every request. If the caller already supplies one (typical when integrating with an existing Datadog / Sentry / OpenTelemetry trace), the SDK respects it instead of minting a fresh id, so trace correlation crosses the SDK boundary cleanly.
- **`Astroway\Internal\LoggingClient`** — PSR-18 client decorator implementing the above. Wraps the existing `RetryClient` so retries surface as separate log entries with the same trace id.

### Changed

- `psr/log` is now a runtime dependency (`^1.1 || ^2.0 || ^3.0`). Existing users who don't pass `logger` are unaffected — the option defaults to `null` (no decoration).

### Migration from beta.4

No breaking changes. New options default to disabled. Logging kicks in only when you opt in.

### Verification

- 117 PHPUnit tests pass (108 baseline + 9 new in `tests/LoggingTest.php`).

### Reference

- [PSR-3 standard](https://www.php-fig.org/psr/psr-3/) — `LoggerInterface` and the eight log levels.
- Monolog [PSR-3 contextual logging guide](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md).
- Datadog APM [`request_id`/`trace_id` correlation](https://docs.datadoghq.com/tracing/other_telemetry/connect_logs_and_traces/).

## 0.1.0-beta.4 — 2026-05-10

Mock client for PHPUnit. Drop-in replacement for `Astroway` that records calls and returns scripted fixtures with zero HTTP traffic. Mirrors `@astroway/sdk/testing` (TS) and `astroway.testing` (Python).

### Added

- **`Astroway\Testing\MockAstroway`** — extends `Astroway`, so the full namespace surface (`$mock->chart()->compute(...)`, all 100+ services) works unchanged with the same type checks. Override is on the public `request()` method:
  ```php
  use Astroway\Testing\MockAstroway;

  $mock = new MockAstroway();
  $mock->respond('POST', '/chart', ['angles' => ['asc' => 'Aries']]);

  $r = $mock->chart()->compute(['date' => '1990-01-01']);
  $this->assertSame('Aries', $r['angles']['asc']);
  $this->assertCount(1, $mock->calls);
  ```
- **`MockAstroway::respond(method, path, fixture)`** — register a fixture as a plain value, a `\Throwable` (thrown when the route is hit), or a `callable(array $ctx): mixed` where `$ctx = ['method', 'path', 'body', 'callIndex']`. Multiple fixtures for the same route serve in order; the last one repeats once exhausted.
- **`MockAstroway::$calls`** — public property: ordered list of `['method', 'path', 'body', 'headers', 'resolved']`.
- **`MockAstroway::callsFor(path, method?)`**, **`callCount()`**, **`reset()`** — assertion helpers.
- **`Astroway\Testing\MockApiError`** — factory for classified `ApiError` subclasses, so retry / error-handling code paths see the right concrete subclass:
  ```php
  $mock->respond('POST', '/chart',
      MockApiError::make(status: 401, code: 'INVALID_API_KEY')   // → AuthenticationError
  );
  $mock->respond('POST', '/chart',
      MockApiError::make(status: 429, retryAfterSeconds: 17)     // → RateLimitError
  );
  $mock->respond('POST', '/chart',
      MockApiError::make(status: 402, code: 'OUT_OF_CREDITS')    // → QuotaExceededError
  );
  ```
- **Helpful error on unmocked routes** — `ApiError("MockAstroway: no fixture for POST /chart. Call \$mock->respond('POST', '/chart', \$value) before invoking this endpoint.")`.

### Changed

- `Astroway` is **non-final** since beta.4. Production users should still treat the class as effectively final — the `@api` surface lives on the public methods, not on inheritance. The change is required so `MockAstroway` can extend it without re-declaring the 100+ namespace service shims.

### Migration from beta.3

No breaking changes for existing code. The `final` removal is a relaxation, not a tightening.

### Verification

- 108 PHPUnit tests pass (98 baseline + 10 new in `tests/Testing/MockAstrowayTest.php`).

### Reference

- Anthropic PHP / TS — `MockAnthropic` pattern.
- [Speakeasy SDK best practices](https://www.speakeasy.com/blog/sdk-best-practices) — gap noted: most generated SDKs ship without test doubles.

## 0.1.0-beta.3 — 2026-05-10

Concurrent batch dispatch. PHP doesn't have native async, but bounded-concurrency batching is critical for "calculate natal charts for 1000 users" workloads. Mirror of `@astroway/sdk` rc.2 plans (TS) — TS rolls connection pooling into the same release.

### Added

- **`$aw->concurrent(int $maxConcurrency = 10)`** — returns `Astroway\Concurrent`:
  ```php
  $charts = $aw->concurrent(5)->all([
      fn() => $aw->charts()->natal($r1),
      fn() => $aw->charts()->natal($r2),
      fn() => $aw->charts()->natal($r3),
  ]);
  ```
- **`Concurrent::all(array $tasks): array`** — runs all callables, returns positional results. Failures land at their index as `Throwable` entries (no early abort) so partial successes are inspectable.
- **`Concurrent::allOrFail(array $tasks): array`** — sequential `try/throw` semantics. First failure aborts and rethrows the original `ApiError` subclass.
- **`Concurrent::map(array $tasks): array`** — preserves your input keys (string or int):
  ```php
  $charts = $aw->concurrent()->map([
      'alice' => fn() => $aw->charts()->natal($alice),
      'bob'   => fn() => $aw->charts()->natal($bob),
  ]);
  ```
- **`maxConcurrency` validation** — throws `InvalidArgumentException` if < 1.

### Why callables, not request objects

Closures capture exactly the call you'd write sequentially — `fn() => $aw->charts()->natal($req)`. Keeps the typed namespace surface and lets DTOs flow through unchanged.

### Implementation notes

The batch loop runs tasks of `$maxConcurrency` chunks back-to-back through the same PSR-18 client. True HTTP parallelism requires Guzzle promises and async transports — exposed via `$concurrent->httpClient()` for users who want to drive the pool directly. The portable contract (sequential within a chunk, bounded chunks) holds across all PSR-18 clients including Symfony's `Psr18Client`.

### Migration from beta.2

No breaking changes. `$aw->concurrent(...)` is purely additive.

### Verification

- 98 phpunit tests pass (9 new in `Astroway\Tests\ConcurrentTest`).
- `phpstan --level=6` clean.
- Coverage: ordered results, error placement at index (not thrown), `allOrFail` first-failure abort, `map` key preservation, `maxConcurrency` validation, public `maxConcurrency` field, empty tasks return empty, instance returned from `Astroway`, `ApiError` subclass preserved through `all()`.

## 0.1.0-beta.2 — 2026-05-10

PSR-16 SimpleCache for deterministic endpoints. Charts are pure functions of `(date, time, lat, lon, tz)` — caching them saves credits and makes dev loops instant. Mirror of `@astroway/sdk` v0.1.0-beta.3 / `astroway` (Python) `b3` plans.

### Added

- **New `cache` constructor option** accepting any PSR-16 `CacheInterface`:
  ```php
  use Astroway\Astroway;
  use Symfony\Component\Cache\Adapter\FilesystemAdapter;
  use Symfony\Component\Cache\Psr16Cache;

  $cache = new Psr16Cache(new FilesystemAdapter('astroway', 0, '/tmp/astroway-cache'));
  $aw = new Astroway(['apiKey' => '...', 'cache' => $cache]);

  // Two identical calls — only one HTTP round-trip
  $aw->charts()->natal($req);
  $aw->charts()->natal($req);
  ```
- **`cacheTtlSeconds` constructor option** — global default TTL (24h by default; pure-function endpoints don't actually expire, but the TTL bounds disk usage).
- **Per-call `cache` override** in `request()` and `post()` (`true` to force, `false` to skip):
  ```php
  $aw->post('/transits', $body, [], cache: true);                 // force-cache
  $aw->request('POST', '/chart', ['json' => $b, 'cache' => false]); // force-skip
  ```
- **Per-call `cacheTtlSeconds`** override the same way.
- **Deterministic-endpoint allowlist** in `Astroway\Internal\CachePolicy::DETERMINISTIC_PREFIXES`:
  - `/chart`, `/synastry`, `/composite`, `/midpoints`, `/aspects`, `/houses`, `/planets`
  - `/vedic/*`, `/numerology/*`, `/tarot/*`, `/hd/*`, `/human-design/*`, `/dasha/*`
- **Time-sensitive denylist** in `CachePolicy::NON_DETERMINISTIC_PREFIXES`:
  - `/transits`, `/horoscope`, `/interpret`, `/ai/*`, `/mcp/*`, `/stream/*`, `/now`, `/today`
- **`Astroway\Internal\CacheKey::build()`** — content-addressed key from canonical JSON. SHA-256 of `(method, path, sorted body)`. Two requests with the same logical body but different field order produce the same key, so caching is order-insensitive (lists keep positional order, intentionally).
- **Cache key namespace `astroway_v1_<hash>`** — bumping the `v1` prefix in a future release auto-invalidates stale entries; multi-SDK Redis backends never collide.

### Compatibility

- PSR-16 `psr/simple-cache: ^1.0 || ^2.0 || ^3.0` is now a hard requirement (~3 KB, no transitive deps). Without a `cache` option in the constructor, behaviour is identical to beta.1.
- `symfony/cache` is a `suggest` (and `require-dev`) for filesystem/Redis/Memcached adapters; users can plug any other PSR-16 implementation.

### Migration from beta.1

No breaking changes. Existing code keeps working without a cache. Adding `'cache' => $psr16Cache` to your constructor opts is the only thing you need to change.

### Verification

- 89 phpunit tests pass (15 new in `Astroway\Tests\CacheTest`).
- `phpstan --level=6` clean.
- Coverage: cache-key stability across key order and nested arrays, list order preserved (positional), method/path differentiation, namespace prefix, deterministic allowlist (chart/synastry/vedic/numerology), non-deterministic denylist (transits/horoscope/interpret/now), unknown endpoints denied by default, end-to-end (deterministic → 1 HTTP call for 2 invocations, non-deterministic → 2 HTTP calls, force-cache via `cache: true`, force-skip via `cache: false`, no-cache backend behaves like beta.1).

## 0.1.0-beta.1 — 2026-05-10

First **beta**. Birth-moment helper that mirrors `@astroway/sdk` v0.1.0-alpha.6 / `astroway` (Python) v0.1.0a6. Less boilerplate around `\DateTimeImmutable` + lat/lon/tz for every astrology call.

### Added

- **`Astroway\Helpers\BirthDateTime`** — `final readonly class` wrapping the (date, time, lat, lon, tz) tuple every calc endpoint expects. Three factories:
  - `BirthDateTime::fromCoordinates(date: '1990-07-14', time: '14:30', latitude: 50.45, longitude: 30.52, timezoneOffset: 3)`
  - `BirthDateTime::fromDateTimeImmutable($dt, latitude: 50.45, longitude: 30.52)` — derives `timezoneOffset` from the `\DateTimeImmutable` offset by default; pass `timezoneOffset:` to override (e.g. UTC instance with original birth tz).
  - `BirthDateTime::parse('1990-07-14T14:30:00+03:00', latitude: 50.45, longitude: 30.52)` — ISO-8601 with offset auto-resolved; naive ISO requires explicit `timezoneOffset:`.
- **`->toArray()`** serialises to the wire shape used by `/v1/chart`, `/v1/synastry`, `/v1/transits`, all `/v1/vedic/*`, etc.
- **`->toDateTimeImmutable()`** rebuilds a PHP datetime including fractional offsets (`+05:30`, `+05:45`).
- Validation in the constructor: date format, time format, latitude `[-90, 90]`, longitude `[-180, 180]`, timezone `[-14, 14]`. Throws `\InvalidArgumentException`.

### Deferred

- `BirthDateTime::fromCity('Kyiv, UA', '1990-07-14', '14:30')` — needs `/v1/geo/search` in api-calc. Until then, geocode externally and pass coordinates to `fromCoordinates()`.

### Verification

- 74 phpunit tests pass (16 new in `Astroway\Tests\Helpers\BirthDateTimeTest`).
- `phpstan --level=6` clean.
- Roundtrip `fromCoordinates() → toDateTimeImmutable() → ATOM` preserves date/time/offset.

## 0.1.0-alpha.6 — 2026-05-10

Auto-attached `Idempotency-Key` (UUIDv4) on every credit-costing POST. Mirror of `@astroway/sdk` v0.1.0-alpha.4 / `astroway` (Python) v0.1.0a4. A network-blip retry never double-bills now.

### Added

- **`Idempotency-Key` header on POST by default.** UUIDv4 per request via `random_bytes(16)` + RFC 4122 v4/variant fixups (no extra dependency). GET/HEAD untouched. User-supplied keys win.
- **`idempotency` constructor option:** `'auto'` (default), `'off'`, or a `callable(): string` (custom generator: deterministic test keys, ULIDs, ...).
- **`idempotencyKey` per-call option** on every service method:
  ```php
  $aw->synastry()->aspectGrid([...], ['idempotencyKey' => 'replay-abc']);
  ```
- **`idempotencyKey` on `$aw->request()`** for manual control over arbitrary methods.
- **`Astroway\Internal\Idempotency::generateKey()`** exposed for users who want the generator standalone.

### Backend coordination

The header fails open. Older backend versions and self-hosted deployments without idempotency support simply ignore it — no breakage. As `api-calc` rolls out idempotency caching, existing SDK users get retry-safe POSTs automatically.

### Internal

- New `src/Internal/Idempotency.php` with `generateKey`, `shouldAttach`, `resolveGenerator` static helpers.
- `Astroway::request()` walks `$options['headers']` case-insensitively to detect existing `Idempotency-Key`.
- Generator widened method `$options` shape to include `idempotencyKey?: string`.
- 58 phpunit tests pass (8 new). PHPStan level 6 clean.

### Migration from alpha.5

No breaking changes. Auto-attachment is additive on POSTs; servers that don't recognise the header ignore it. To suppress globally: `new Astroway(['apiKey' => …, 'idempotency' => 'off'])`.

## 0.1.0-alpha.5 — 2026-05-10

Refined error hierarchy + uniform `creditsRemaining` / `retryAfterSeconds` on every `ApiError`. Mirror of TS alpha.5 / Python a5.

### Added

- **`QuotaExceededError`** — distinguishes "you ran out of credits" from "you got rate-limited" (the latter resolves with backoff; the former needs a top-up). Triggered by HTTP 402 or `errorCode: OUT_OF_CREDITS` / `QUOTA_EXCEEDED` / `CREDIT_LIMIT_REACHED`.
- **`CalculationError`** — for server-side calculation failures (Swiss Ephemeris boundaries, missing datasets, unsupported house systems for high latitudes). Triggered by `errorCode: CALCULATION_ERROR` / `EPHEMERIS_ERROR`.
- **`creditsRemaining`** field uniform across all `ApiError` subclasses, surfaced from `X-Credits-Remaining` response header.
- **`retryAfterSeconds`** moved from `RateLimitError` to base `ApiError` — useful on quota-exceeded responses too, not just 429.

### Changed

- `RateLimitError` constructor signature unchanged for callers (positional + named args still work); the `retryAfterSeconds` property now lives on the base `ApiError`.

### Migration from alpha.4

No breaking source changes. Existing code that catches `RateLimitError` and reads `$e->retryAfterSeconds` keeps working — the field just lives on the base `ApiError` now (also reachable as `($e instanceof ApiError ? $e->retryAfterSeconds : null)`).

```php
use Astroway\Errors\{RateLimitError, QuotaExceededError, CalculationError};

try {
    $aw->chart()->compute([...]);
} catch (RateLimitError $e) {
    sleep($e->retryAfterSeconds ?? 60);
} catch (QuotaExceededError $e) {
    // $e->creditsRemaining is often 0 here — top up
    notifyBilling($e->creditsRemaining);
} catch (CalculationError $e) {
    // ephemeris boundary — try a different date or house system
    skipDate($e->body);
}
```

### Internal

- `Astroway::raiseForResponse()` now reads `X-Credits-Remaining` and threads `creditsRemaining` into every classified error.
- `Classify::fromStatus()` does code-first dispatch for app-level errors that may ride on multiple HTTP statuses.
- 50 phpunit tests pass (6 new). PHPStan level 6 clean.

## 0.1.0-alpha.4 — 2026-05-10

DTO request classes for the top-4 endpoint categories. PHP 8.1+ readonly classes with constructor-promotion + format validation at construction time. IDE autocomplete, fewer typos, and request bodies that fail fast before the network round-trip.

### Added

- **`Astroway\Dto`** namespace with hand-curated readonly classes:
  - `BirthData` — base for natal-style endpoints (date, time, timezoneOffset, latitude, longitude, houseSystem, name, city, zodiacType, ayanamsaId, cosmogram).
  - `SynastryRequest` — `chart1: BirthData`, `chart2: BirthData`, `orbFactor`.
  - `TransitsRequest` — flat birth fields + `targetDate` / `targetTime` / `target*` overrides.
  - `VedicDashaRequest` — birth + `ayanamsaId` + `startDate` / `endDate` window.
- **Dual input on every service method** — pass a DTO or an array; `request()` calls `toArray()` automatically when given any object exposing the method.
- **Format validation** — `date` (`YYYY-MM-DD`) and `time` (`HH:MM:SS`) patterns enforced in constructors, throws `InvalidArgumentException` on bad input.
- **Readonly properties** — DTOs are immutable (PHP 8.1 `readonly` + constructor promotion).
- **Top-level `$aw->post(path, body, query)` widened** to accept `array|object|null` for body — pass a DTO directly.

### Unchanged

- All alpha.3 surface preserved: 103 services / 623 methods, memoized accessors, escape hatches (`$aw->request`, `$aw->post`).
- Remaining 90+ namespaces still accept array bodies — coverage will expand alongside Python's.

### Migration from alpha.3

No breaking changes. DTOs are additive — existing array calls keep working unchanged.

```php
// Both work identically:
$aw->chart()->compute(['date' => '1990-07-14', 'time' => '14:30:00', 'timezoneOffset' => 3]);
$aw->chart()->compute(new \Astroway\Dto\BirthData(date: '1990-07-14', time: '14:30:00', timezoneOffset: 3));
```

### Internal

- Generator widened method signatures from `?array $body` to `array|object|null $body` so DTOs type-check at the call site.
- 44 phpunit tests pass (9 new DTO tests). PHPStan level 6 clean.

## 0.1.0-alpha.3 — 2026-05-10

Typed service classes — `$aw->synastry()->aspectGrid([...])` instead of `$aw->post('/synastry/aspect-grid', body: [...])`. Same typing, friendlier surface, automatic envelope unwrap.

### Added

- **103 service namespaces / 623 methods** auto-generated from `openapi.json`. Naming mirrors the TS / Python SDKs: `_` is the namespace separator, `-` becomes camelCase per segment. Single-segment opIds get `compute()`.
  - `$aw->transits()->compute([...])` — POST `/transits`
  - `$aw->synastry()->aspectGrid([...])` — POST `/synastry/aspect-grid`
  - `$aw->bazi()->dayMaster([...])` — POST `/bazi/day-master`
  - `$aw->vedic()->dashasVimshottariMaha([...])` — POST `/vedic/dashas/vimshottari/maha`
  - `$aw->tarot()->riderWaiteDaily([...])` — POST `/tarot/rider-waite/daily`
  - `$aw->humanDesign()->compute([...])` — POST `/human-design`
- **Memoized accessors.** Each `$aw->namespace()` returns the same service instance for the lifetime of the Astroway client.
- **Per-call options:** `['headers' => […], 'query' => […]]` second argument on every service method.
- **`scripts/generate-namespaces.php`** wired into `composer generate:namespaces`.

### Unchanged

- `$aw->request($method, $path, $opts)` and `$aw->post(...)` / `$aw->get(...)` escape hatches still work — needed for path-template endpoints (`/webhooks/{id}/test`) and anything not yet covered by services.
- All alpha.2 surface preserved: PSR-18/17 BYOC, error hierarchy, retry, identification headers, auth schemes.

### Internal

- New `Astroway\HasServices` trait holds the 103 accessor methods (auto-generated). `Astroway` class uses the trait.
- 35 phpunit tests pass (6 new namespace tests). PHPStan level 6 clean (with `--memory-limit=1G` for the larger generated tree).

### Migration from alpha.2

No breaking changes. Service accessors are additive on the `Astroway` instance via trait. Replace `$aw->post('/x/y', body: [...])` with `$aw->x()->y([...])` at your own pace — both still work.

## 0.1.0-alpha.2 — 2026-05-10

Bring Your Own HTTP Client (PSR-18/17). Guzzle is no longer a hard dependency — the SDK runs on any PSR-18 client (Guzzle, Symfony HTTP, Buzz, …) via `php-http/discovery` auto-detection or explicit injection.

### Changed

- **Hard `guzzlehttp/guzzle` dependency dropped.** Now only `psr/http-client`, `psr/http-message`, `psr/http-factory`, `php-http/discovery` in `require`. Install Guzzle (or any PSR-18 client) once: `composer require guzzlehttp/guzzle nyholm/psr7`.
- **`RetryClient` PSR-18 decorator** replaces the Guzzle-specific `RetryMiddleware`. Same retry semantics (408/409/429/5xx + network errors, exp backoff + jitter, `Retry-After` honored), now portable across HTTP clients.
- **Constructor options:** `httpClient`, `requestFactory`, `streamFactory` for explicit BYOC injection. The `handlerStack` Guzzle-specific option was removed — alpha stage, no BC promise. Pass a configured PSR-18 `ClientInterface` to `httpClient` instead.

### Internal

- Tests rewritten to use a minimal in-tree `MockHttpClient` (PSR-18) plus `nyholm/psr7` for response building. No more `GuzzleHttp\Handler\MockHandler` / `HandlerStack` / `Middleware::history` coupling.
- Added 3 new tests covering query-string encoding, JSON body serialization, and explicit `httpClient` injection (29 tests total, was 26).

### Migration from alpha.1

If you used the default Astroway constructor without overrides, **no code changes required** — auto-discovery picks up Guzzle if you already have it installed.

If you passed `'handlerStack' => $stack` (Guzzle-specific): rebuild your customised stack into a configured `GuzzleHttp\Client` and pass it as `'httpClient' => $client` instead.

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
