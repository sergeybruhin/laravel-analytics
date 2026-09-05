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
