<?php

namespace SergeyBruhin\Analytics\Support;

use DateTimeInterface;
use Illuminate\Support\Collection;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Event;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Session;

/**
 * Read side for a «customer journey» view: the sessions a set of visitors had
 * (newest first), each with its pageviews and events in order, plus the events
 * recorded server-side without a session (a purchase confirmed by a payment
 * webhook, say).
 *
 * The host decides which visitors (Analytics::visitorIdsFor() for a customer,
 * an order's own visitor id) and merges in its own milestones — orders,
 * payments, shipments — that this package knows nothing about. Bot sessions
 * are left out; internal ones are kept and flagged (`is_internal`), since a
 * journey is looked at one customer at a time and hiding a staff test there
 * would only confuse.
 *
 * Reads through the postgres driver's models (the only driver today).
 */
final class VisitorJourney
{
    /**
     * @param  list<string>  $visitorIds
     */
    public function __construct(
        private readonly array $visitorIds,
        private readonly int $limit = 20,
        private readonly ?DateTimeInterface $since = null,
        private readonly ?DateTimeInterface $until = null,
    ) {}

    /**
     * @return Collection<int, Session> newest first, `pageviews` and `events` loaded oldest first
     */
    public function sessions(): Collection
    {
        if ($this->visitorIds === []) {
            return collect();
        }

        return Session::query()
            ->whereIn('visitor_id', $this->visitorIds)
            ->where('is_bot', false)
            ->when($this->since, fn ($query, $since) => $query->where('started_at', '>=', $since))
            ->when($this->until, fn ($query, $until) => $query->where('started_at', '<=', $until))
            ->orderByDesc('started_at')
            ->limit($this->limit)
            ->with([
                'pageviews' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'events' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            ])
            ->get();
    }

    /**
     * Events with no session (recorded from the server), within the window.
     *
     * @return Collection<int, Event> oldest first
     */
    public function sessionlessEvents(): Collection
    {
        if ($this->visitorIds === []) {
            return collect();
        }

        return Event::query()
            ->whereIn('visitor_id', $this->visitorIds)
            ->whereNull('session_id')
            ->when($this->since, fn ($query, $since) => $query->where('created_at', '>=', $since))
            ->when($this->until, fn ($query, $until) => $query->where('created_at', '<=', $until))
            ->orderBy('created_at')
            ->limit($this->limit * 10)
            ->get();
    }

    /** True when the visitors have more sessions than the limit returned. */
    public function hasMore(): bool
    {
        if ($this->visitorIds === []) {
            return false;
        }

        return Session::query()
            ->whereIn('visitor_id', $this->visitorIds)
            ->where('is_bot', false)
            ->when($this->since, fn ($query, $since) => $query->where('started_at', '>=', $since))
            ->when($this->until, fn ($query, $until) => $query->where('started_at', '<=', $until))
            ->count() > $this->limit;
    }
}
