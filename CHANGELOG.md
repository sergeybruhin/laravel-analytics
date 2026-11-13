# Changelog

## Unreleased (proposed 0.0.4)

- **Internal traffic.** `Analytics::resolveInternalUsing()` lets the host flag its own
  requests (staff, test devices). Flagged visitors stay internal, and their sessions are
  stored with `is_internal`. `markInternal()`, `unmarkInternal()` and `isInternal()` are
  on `Analytics` and on the store contract.
- **Multi-device identity.** `(site, external_id)` is no longer unique, so one customer can
  own several visitors. New `Analytics::visitorIdsFor()`. A bare `identify()` no longer
  wipes earlier traits.
- **`Support\TrafficSourceClassifier`**: utm tags, ad click ids and the referrer resolve to
  a channel (paid, organic, social, email, referral, campaign, direct) and a source.
- **`Support\VisitorJourney`**: sessions with their pageviews and events for a set of
  visitors, for per-customer timelines.
- **Migration** `2024_01_01_000006_add_internal_flags_and_multi_device_identity`. Hosts
  must publish it and run migrate.
- The test suite can run on SQLite (`ANALYTICS_TEST_DB_DRIVER=sqlite`). The sessions
  partial index is created on pgsql only.

## 0.0.3 and earlier

See the [GitHub releases](https://github.com/sergeybruhin/laravel-analytics/releases).
