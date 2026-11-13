<?php

namespace SergeyBruhin\Analytics\Contracts;

/**
 * The storage-driver boundary. Every method receives a flat, fully
 * denormalized attribute array — no implementation may assume it can join
 * across tables to fill in a value. ClickHouse (a likely future driver for
 * high-volume installs) has no foreign keys, no meaningful UNIQUE constraint
 * enforcement, and prefers batched over row-at-a-time writes; keeping the
 * contract to that lowest common denominator now means adding a second
 * driver later never requires changing this interface.
 */
interface AnalyticsStore
{
    /**
     * Create or update a visitor. Only fields present in $attributes are
     * touched; first-touch fields (first_utm_*, first_click_ids, ...) are
     * set once and never overwritten on subsequent calls for the same id.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertVisitor(string $visitorId, string $site, array $attributes): void;

    /**
     * Create or update a session, applying last-touch attribution semantics:
     * a non-direct hit overwrites the utm and click-id fields, a direct hit
     * only touches last_seen-style fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function touchSession(string $sessionId, string $visitorId, string $site, array $attributes): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordPageview(array $attributes): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordEvent(array $attributes): void;

    /**
     * Link an anonymous visitor to a host-defined external identity (e.g. a
     * logged-in customer id), with optional free-form traits.
     *
     * @param  array<string, mixed>  $traits
     */
    public function identify(string $visitorId, string $externalId, array $traits = []): void;

    /**
     * Flag a visitor as the host's own traffic (staff, a test device), for
     * good, together with every session it has opened. Idempotent.
     */
    public function markInternal(string $visitorId): void;

    /**
     * Clear the flag set by markInternal() — for a visitor marked by mistake.
     */
    public function unmarkInternal(string $visitorId): void;

    public function isInternal(string $visitorId): bool;
}
