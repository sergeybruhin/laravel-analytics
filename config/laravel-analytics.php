<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site key
    |--------------------------------------------------------------------------
    |
    | Written onto every visitor/session/pageview/event row. Lets several
    | projects share one external analytics database (each install's
    | ANALYTICS_DB_* pointed at the same Postgres host) without their rows
    | colliding — reporting just filters/groups by this column.
    |
    */

    'site' => env('ANALYTICS_SITE_KEY', config('app.name')),

    /*
    |--------------------------------------------------------------------------
    | Storage driver
    |--------------------------------------------------------------------------
    |
    | "postgres" is the only driver shipped today. The write path
    | (CollectController -> RecordAnalyticsHitJob -> AnalyticsStore) never
    | references it directly, so a future driver (e.g. ClickHouse, for an
    | install that outgrows Postgres for this workload) is a new class plus
    | an entry here — not a rewrite of ingestion.
    |
    */

    'driver' => env('ANALYTICS_DRIVER', 'postgres'),

    'drivers' => [
        'postgres' => [
            // Name of the Laravel DB connection (config/database.php) this
            // driver writes through. Left at "analytics" by default; that
            // connection resolves to the same database as the app's own
            // connection unless ANALYTICS_DB_* env vars override it.
            'connection' => env('ANALYTICS_DB_CONNECTION', 'analytics'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Analytics ingestion runs on its own queue connection so a checkout- or
    | fulfillment-critical queue is never starved by hit volume, and so its
    | retry/backoff settings can stay short (this data is best-effort).
    |
    */

    'queue_connection' => env('ANALYTICS_QUEUE_CONNECTION', 'redis_analytics'),

    /*
    |--------------------------------------------------------------------------
    | Ingestion rate limit
    |--------------------------------------------------------------------------
    |
    | The collect endpoint is anonymous and CSRF-exempt (sendBeacon cannot set
    | a header), so it is throttled — but it is also the one endpoint a normal
    | visitor hits simply by browsing, and the reference tracker flushes on a
    | five-second timer *per tab*. A limit sized for a form submission turns
    | ordinary use into 429s, and a 429 here is not a lost metric: on a host
    | whose throttle middleware shares one counter per visitor, it is a lost
    | checkout on a completely unrelated route.
    |
    | So: generous by default, and keyed per visitor with a per-IP backstop, so
    | a NAT'd office or a carrier gateway is not one visitor. Set the per-minute
    | figure to 0 to disable throttling entirely — only sensible where something
    | in front of the app (a CDN, a WAF) is already doing it.
    |
    */

    'rate_limit' => [
        // Per visitor_id + IP. The visitor id is client-generated and therefore
        // not to be trusted on its own, which is what the backstop is for.
        'per_visitor' => (int) env('ANALYTICS_RATE_LIMIT_PER_VISITOR', 300),

        // Per IP, whatever visitor ids are claimed behind it. Sized for a large
        // shared egress rather than one person.
        'per_ip' => (int) env('ANALYTICS_RATE_LIMIT_PER_IP', 3000),

        'decay_minutes' => (int) env('ANALYTICS_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Enforced by the analytics:prune command — register it on the
    | scheduler. Plain DELETE, not partition-drop: the confirmed scale
    | target for this rollout is small enough that bulk deletes are cheap;
    | an install that outgrows this should look at the ClickHouse driver
    | path rather than retrofitting Postgres partitioning.
    |
    */

    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 395),

    /*
    |--------------------------------------------------------------------------
    | IP storage
    |--------------------------------------------------------------------------
    |
    | When enabled, only a truncated IP is ever persisted (see
    | Support\IpAnonymizer) — never the raw address. Set to false to skip
    | storing IP-derived data entirely.
    |
    */

    'store_ip' => (bool) env('ANALYTICS_STORE_IP', true),

];
