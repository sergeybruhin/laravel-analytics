<?php

namespace SergeyBruhin\Analytics;

use Closure;
use Illuminate\Http\Request;
use SergeyBruhin\Analytics\Contracts\AnalyticsStore;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Visitor;

/**
 * Thin, container-resolvable entry point for host application code (e.g. an
 * Event listener recording a server-side goal) that wants to write an event
 * without depending on the currently configured driver directly.
 */
class Analytics
{
    /** @var (Closure(Request): bool)|null */
    protected static ?Closure $internalResolver = null;

    public function __construct(protected AnalyticsStore $store) {}

    /**
     * Teach the collector which requests are the host's own traffic — staff
     * signed in to the admin panel, a test device carrying a cookie, an office
     * IP. Evaluated on every collect request, in the request's context (session
     * and cookies available); a visitor seen once as internal stays internal.
     *
     *     Analytics::resolveInternalUsing(fn (Request $request) => auth('web')->check());
     *
     * Pass null to forget a resolver (tests).
     *
     * @param  (Closure(Request): bool)|null  $resolver
     */
    public static function resolveInternalUsing(?Closure $resolver): void
    {
        static::$internalResolver = $resolver;
    }

    public static function isInternalRequest(Request $request): bool
    {
        return static::$internalResolver !== null && (bool) (static::$internalResolver)($request);
    }

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
     * Link a visitor to a host identity (e.g. "customer:42"). Many visitors —
     * a customer's phone and laptop — may share one external id.
     *
     * @param  array<string, mixed>  $traits
     */
    public function identify(string $visitorId, string $externalId, array $traits = []): void
    {
        $this->store->identify($visitorId, $externalId, $traits);
    }

    public function markInternal(string $visitorId): void
    {
        $this->store->markInternal($visitorId);
    }

    public function unmarkInternal(string $visitorId): void
    {
        $this->store->unmarkInternal($visitorId);
    }

    public function isInternal(string $visitorId): bool
    {
        return $this->store->isInternal($visitorId);
    }

    /**
     * Every visitor id linked to an external id on this site, oldest first.
     *
     * @return list<string>
     */
    public function visitorIdsFor(string $externalId, ?string $site = null): array
    {
        return Visitor::query()
            ->where('site', $site ?? (string) config('laravel-analytics.site'))
            ->where('external_id', $externalId)
            ->orderBy('first_seen_at')
            ->pluck('id')
            ->all();
    }

    public function store(): AnalyticsStore
    {
        return $this->store;
    }
}
