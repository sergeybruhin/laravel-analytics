# sergeybruhin/laravel-analytics

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sergeybruhin/laravel-analytics.svg)](https://packagist.org/packages/sergeybruhin/laravel-analytics)
[![License](https://img.shields.io/github/license/sergeybruhin/laravel-analytics.svg)](LICENSE.md)

First-party, event-based analytics for Laravel apps — visitors, sessions,
pageviews, and generic named events (goals) — with a pluggable storage
driver. Ships one fully-built driver (Postgres) today; a higher-volume
driver (e.g. ClickHouse) can be added later without touching the ingestion
path, the queue job, or the frontend tracker.

**This package is completely independent of any third-party analytics
vendor.** It has no concept of Yandex Metrika, Google Analytics, or any
other SaaS — no `ym_client_id`-shaped field, no vendor goal registry,
nothing. If a host app also runs a vendor tag, the two simply don't know
about each other.

## Why

Vendor analytics tools own your data and rarely let you join it against
your own schema (a cart, an order, a customer). This package gives a host
app a first-party visitor → session → pageview/event log it can query,
join, and own outright — while staying small enough to run in the same
database as the app itself for a low-volume install.

## Requirements

- PHP ^8.2, Laravel ^12.0
- The Postgres driver (the only one shipped) requires a PostgreSQL
  connection — same database as the app, or a separate instance/host.

## Installation

Once published on Packagist:

```bash
composer require sergeybruhin/laravel-analytics
```

Until then (or to track a specific tag/branch directly from GitHub), add a
VCS repository to the host app's `composer.json` first:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/sergeybruhin/laravel-analytics"
    }
]
```

then run the same `composer require` command above.

The service provider auto-registers via Laravel package discovery.

Publish config and migrations, then run them **against the `analytics`
connection specifically** — a plain `php artisan migrate` would silently
skip them:

```bash
php artisan vendor:publish --tag=analytics-config
php artisan vendor:publish --tag=analytics-migrations
php artisan migrate --database=analytics
```

Or run all three in one step:

```bash
php artisan analytics:install
```

> This package's own hard rule, matching this repo's CLAUDE.md: never run
> `migrate` (or `analytics:install`, which calls it) automatically from
> tooling — always hand these commands to whoever operates the app.

## Configuration

Set in `.env`:

```dotenv
ANALYTICS_SITE_KEY=my-project        # written onto every row; lets several
                                      # projects share one external DB
ANALYTICS_DRIVER=postgres            # the only driver shipped today

# Leave ANALYTICS_DB_* unset to run in "same database" mode: the driver
# reuses the app's own DB_* credentials and isolates its tables in a
# dedicated "analytics" Postgres schema (via search_path), not a second
# database or table prefix.
#
# Set these to point at a separate Postgres instance instead — e.g. a
# second docker-compose service, or a shared external Postgres used by
# several projects (rows are kept apart by the `site` column above).
ANALYTICS_DB_HOST=
ANALYTICS_DB_PORT=
ANALYTICS_DB_DATABASE=
ANALYTICS_DB_USERNAME=
ANALYTICS_DB_PASSWORD=
ANALYTICS_DB_SCHEMA=analytics
ANALYTICS_DB_SSLMODE=prefer

ANALYTICS_QUEUE_CONNECTION=redis_analytics   # see "Queueing" below
ANALYTICS_RETENTION_DAYS=395                 # used by analytics:prune
ANALYTICS_STORE_IP=true                      # false = store no IP-derived data at all
```

Full list with comments: `config/laravel-analytics.php`.

## Queueing

All storage writes happen on a queue — the collect endpoint only validates
and dispatches, then returns `202` immediately, so a slow or unavailable
analytics database never delays a request. Add a dedicated queue
connection (own `retry_after`, since analytics writes are best-effort and
shouldn't compete with app-critical queues):

```php
// config/queue.php
'connections' => [
    // ...
    'redis_analytics' => [
        'driver' => 'redis',
        'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queue' => env('ANALYTICS_QUEUE', 'analytics'),
        'retry_after' => (int) env('REDIS_ANALYTICS_RETRY_AFTER', 60),
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

If you run Horizon, add a supervisor for it (short tries/backoff — a
dropped analytics hit is not worth retrying aggressively):

```php
'supervisor-analytics' => [
    'connection' => 'redis_analytics',
    'queue' => ['analytics'],
    'balance' => 'auto',
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'memory' => 128,
    'tries' => 2,
    'timeout' => 30,
],
```

## CSRF exemption

`sendBeacon` cannot attach custom headers, so the collect endpoint must be
CSRF-exempt:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'api/analytics/collect',
    ]);
})
```

The endpoint is protected instead by request throttling, an
origin/referrer check, and strict payload validation (see "Security
model" below).

## Frontend

`resources/js/tracker.ts` is a **reference implementation**, not a
publishable asset — copy it into your own Vite build and adapt the two
marked "integration point" spots (UTM/click-id extraction, and the CSRF
posture of the delivery call) to your project's own conventions. It has no
dependency on any specific host project's CustomEvents, UTM helper, or
CSRF helper.

Public API once loaded:

```js
window.Analytics.track(name, properties = {}, value);
window.Analytics.identify(externalId, traits = {});
window.Analytics.trackPageview(); // only needed for a client-side router;
                                   // the first pageview fires automatically
```

Behavior:
- Automatic pageview on load; `duration_ms` is filled in on the next flush
  or on unload (`pagehide` / `visibilitychange`), whichever comes first.
- Hits batch in memory and flush every 5s, at a 20-hit cap, or on unload
  via `sendBeacon` (falling back to `fetch(..., {keepalive: true})`).
- Every event gets a client-generated `dedup_key` so a retried/duplicate
  flush can't double-count it (pageviews are not deduplicated — there is
  no equivalent guarantee needed, a duplicate pageview is harmless).
- Visitor id: `crypto.randomUUID()` in `localStorage` (`qskn_vid`).
  Deliberately a separate identifier from any cart/session id the host app
  already keeps — don't repurpose an existing one.

## Backend: writing events from PHP

Resolve the `Analytics` class from the container anywhere server-side —
e.g. to record a purchase that doesn't depend on client-side JS having
fired:

```php
use SergeyBruhin\Analytics\Analytics;

app(Analytics::class)->track(
    visitorId: $order->visitor_id,
    site: config('laravel-analytics.site'),
    name: 'purchase',
    properties: ['order_id' => $order->id],
    value: (float) $order->grand_total,
    dedupKey: "order-{$order->id}",
);
```

Do this from a queued listener on your own domain event (e.g.
`OrderPlaced`), not inline in a controller — keep the analytics write off
the request path that has to succeed for checkout.

## Data model

Four tables on the `analytics` connection, all carrying a `site` column:

- **`visitors`** — one row per anonymous visitor (uuid id). First-touch
  `utm_*`/click-id fields, set once and never overwritten. Optional
  `external_id` + `traits` (jsonb) once `identify()` is called.
- **`sessions`** — one row per session (uuid id), FK to visitor. Last-touch
  `utm_*`/click-ids (a non-direct hit overwrites these; a direct hit
  doesn't), device/browser/OS, `is_bot`, truncated IP.
- **`pageviews`** — one row per page load. `url`, `path`, `referrer`,
  `title`, `duration_ms`.
- **`events`** — one generic row per named event (`purchase`,
  `add_to_cart`, `signup`, anything). `name`, `properties` (jsonb),
  `value` (nullable numeric), `dedup_key` (unique per site).

The package has no ecommerce-specific tables or columns — `events` is
intentionally generic. Only `utm_source/medium/campaign/content/term` are
normalized columns; everything else platform- or app-specific (click ids,
event properties, identify traits) is JSON.

IPs, when stored at all, are truncated (last IPv4 octet / last 80 bits of
IPv6 zeroed) before being persisted — never the raw address. Disable
entirely with `ANALYTICS_STORE_IP=false`.

## Security model

The collect endpoint is anonymous and unauthenticated by design. It's
protected by, in order:

1. `throttle:120,1` per IP.
2. `VerifyAnalyticsOrigin` — compares `Origin`/`Referer` against
   `config('app.url')`. A mismatch gets a silent `202` no-op, never a
   `403`, so a prober can't distinguish "blocked" from "accepted."
3. `CollectRequest` — strict shape validation: batch capped at 20 hits,
   event names restricted to `^[a-z0-9_.-]{1,191}$`, `properties` capped
   at 4KB encoded.
4. `site` is always taken from server config (`laravel-analytics.site`),
   never from the client payload.

Bots are detected (`jenssegers/agent` + a UA-substring blocklist) and
flagged (`is_bot = true`), not silently dropped — so your exclusion rate
stays auditable instead of just disappearing.

## Retention

```bash
php artisan analytics:prune
```

Deletes pageviews/events older than `ANALYTICS_RETENTION_DAYS` (plain
`DELETE`, not partition-drop — fine at the small-to-medium scale this
driver targets). Register it on your scheduler:

```php
// routes/console.php
Schedule::command('analytics:prune')->daily();
```

## Nova reporting (optional, Postgres driver only)

```php
use SergeyBruhin\Analytics\Drivers\Postgres\Nova\AnalyticsVisit;
use SergeyBruhin\Analytics\Drivers\Postgres\Metrics\PageviewsTrend;
use SergeyBruhin\Analytics\Drivers\Postgres\Metrics\TopTrafficSources;
```

Register `AnalyticsVisit` (read-only) as a Nova resource, and add
`PageviewsTrend`/`TopTrafficSources` to a dashboard's `cards()`. This
reporting layer is Postgres-specific; a future driver would need its own,
or a host can point an external BI tool at whichever store is active.

## Adding a storage driver

Implement `SergeyBruhin\Analytics\Contracts\AnalyticsStore` and register it
via `DriverManager::extend()`:

```php
app(\SergeyBruhin\Analytics\Drivers\DriverManager::class)
    ->extend('clickhouse', fn ($app) => new ClickHouseStore(/* ... */));
```

```dotenv
ANALYTICS_DRIVER=clickhouse
```

Every contract method receives a flat, fully denormalized attribute array
— no method may assume the driver can join across tables to fill in a
value, since not every backend (e.g. ClickHouse) has foreign keys or row-
level unique constraints. Nothing in `CollectController`,
`RecordAnalyticsHitJob`, or the frontend tracker changes when a new driver
is added.

## Testing

```bash
composer install
cp .env.testing.example .env.testing   # if present, else set ANALYTICS_TEST_DB_* env vars
vendor/bin/phpunit
```

The suite (`tests/`) uses Orchestra Testbench and runs the Postgres driver
against a real Postgres connection — set `ANALYTICS_TEST_DB_HOST` /
`_PORT` / `_DATABASE` / `_USERNAME` / `_PASSWORD` to point it at a
disposable database/schema before running.

## Changelog

See the [GitHub releases](https://github.com/sergeybruhin/laravel-analytics/releases) for a version history.

## License

MIT — see [LICENSE.md](LICENSE.md).
