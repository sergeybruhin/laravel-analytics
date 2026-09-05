<?php

namespace SergeyBruhin\Analytics;

use SergeyBruhin\Analytics\Contracts\AnalyticsStore;

/**
 * Thin, container-resolvable entry point for host application code (e.g. an
 * Event listener recording a server-side goal) that wants to write an event
 * without depending on the currently configured driver directly.
 */
class Analytics
{
    public function __construct(protected AnalyticsStore $store) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function track(
        string $visitorId,
        string $site,
        string $name,
        array $properties = [],
        float|int|null $value = null,
        ?string $sessionId = null,
        ?string $dedupKey = null,
    ): void {
        $this->store->recordEvent([
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'site' => $site,
            'name' => $name,
            'properties' => $properties,
            'value' => $value,
            'dedup_key' => $dedupKey,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $traits
     */
    public function identify(string $visitorId, string $externalId, array $traits = []): void
    {
        $this->store->identify($visitorId, $externalId, $traits);
    }

    public function store(): AnalyticsStore
    {
        return $this->store;
    }
}
