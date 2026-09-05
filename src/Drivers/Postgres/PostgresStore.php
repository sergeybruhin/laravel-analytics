<?php

namespace SergeyBruhin\Analytics\Drivers\Postgres;

use SergeyBruhin\Analytics\Contracts\AnalyticsStore;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Event;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Pageview;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Session;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Visitor;

class PostgresStore implements AnalyticsStore
{
    public function __construct(protected string $connection) {}

    public function upsertVisitor(string $visitorId, string $site, array $attributes): void
    {
        $visitor = Visitor::on($this->connection)->find($visitorId);

        if ($visitor === null) {
            Visitor::on($this->connection)->create(array_merge($attributes, [
                'id' => $visitorId,
                'site' => $site,
                'first_seen_at' => $attributes['first_seen_at'] ?? now(),
                'last_seen_at' => now(),
            ]));

            return;
        }

        // First-touch fields are set once, at creation, above — a repeat
        // visitor only ever bumps last_seen_at here, never overwrites them.
        $visitor->forceFill(['last_seen_at' => now()])->save();
    }

    public function touchSession(string $sessionId, string $visitorId, string $site, array $attributes): void
    {
        $session = Session::on($this->connection)->find($sessionId);

        if ($session === null) {
            Session::on($this->connection)->create(array_merge($attributes, [
                'id' => $sessionId,
                'visitor_id' => $visitorId,
                'site' => $site,
                'started_at' => $attributes['started_at'] ?? now(),
            ]));

            return;
        }

        // Last-non-direct-click-wins: the caller only includes utm_*/click_ids
        // keys in $attributes when the hit carried explicit attribution, so a
        // direct/organic revisit here only updates bookkeeping fields like
        // exit_url, matching resources/views/web/plugins/utm-meta.ts's semantics.
        $session->forceFill($attributes)->save();
    }

    public function recordPageview(array $attributes): void
    {
        Pageview::on($this->connection)->create($attributes);
    }

    public function recordEvent(array $attributes): void
    {
        if (! empty($attributes['dedup_key'])) {
            $alreadyRecorded = Event::on($this->connection)
                ->where('site', $attributes['site'])
                ->where('dedup_key', $attributes['dedup_key'])
                ->exists();

            if ($alreadyRecorded) {
                return;
            }
        }

        Event::on($this->connection)->create($attributes);
    }

    public function identify(string $visitorId, string $externalId, array $traits = []): void
    {
        Visitor::on($this->connection)->whereKey($visitorId)->update([
            'external_id' => $externalId,
            'traits' => $traits === [] ? null : $traits,
        ]);
    }
}
