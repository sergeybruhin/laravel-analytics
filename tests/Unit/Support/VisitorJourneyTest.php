<?php

namespace SergeyBruhin\Analytics\Tests\Unit\Support;

use Illuminate\Support\Str;
use SergeyBruhin\Analytics\Drivers\Postgres\PostgresStore;
use SergeyBruhin\Analytics\Support\VisitorJourney;
use SergeyBruhin\Analytics\Tests\TestCase;

class VisitorJourneyTest extends TestCase
{
    public function test_returns_human_sessions_newest_first_with_their_hits_in_order(): void
    {
        $store = new PostgresStore('analytics');
        $visitor = (string) Str::uuid();
        $other = (string) Str::uuid();
        $store->upsertVisitor($visitor, 'test-site', []);
        $store->upsertVisitor($other, 'test-site', []);

        $old = $this->makeSession($store, $visitor, now()->subDays(3));
        $new = $this->makeSession($store, $visitor, now()->subDay());
        $bot = $this->makeSession($store, $visitor, now(), isBot: true);
        $this->makeSession($store, $other, now());

        foreach (['/b', '/a'] as $offset => $path) {
            $store->recordPageview(['session_id' => $new, 'visitor_id' => $visitor, 'site' => 'test-site', 'url' => 'https://example.test'.$path, 'path' => $path, 'created_at' => now()->subDay()->addMinutes($offset)]);
        }
        $store->recordEvent(['session_id' => $new, 'visitor_id' => $visitor, 'site' => 'test-site', 'name' => 'add_to_cart', 'properties' => [], 'created_at' => now()->subDay()]);
        $store->recordEvent(['session_id' => null, 'visitor_id' => $visitor, 'site' => 'test-site', 'name' => 'purchase', 'properties' => [], 'value' => 4900, 'created_at' => now()]);

        $journey = new VisitorJourney([$visitor]);
        $sessions = $journey->sessions();

        $this->assertSame([$new, $old], $sessions->pluck('id')->all());
        $this->assertNotContains($bot, $sessions->pluck('id')->all());
        $this->assertSame(['/b', '/a'], $sessions->first()->pageviews->pluck('path')->all());
        $this->assertSame(['add_to_cart'], $sessions->first()->events->pluck('name')->all());
        $this->assertSame(['purchase'], $journey->sessionlessEvents()->pluck('name')->all());
        $this->assertFalse($journey->hasMore());
        $this->assertTrue((new VisitorJourney([$visitor], limit: 1))->hasMore());
    }

    public function test_no_visitors_means_no_queries_and_no_journey(): void
    {
        $journey = new VisitorJourney([]);

        $this->assertTrue($journey->sessions()->isEmpty());
        $this->assertTrue($journey->sessionlessEvents()->isEmpty());
        $this->assertFalse($journey->hasMore());
    }

    private function makeSession(PostgresStore $store, string $visitor, $startedAt, bool $isBot = false): string
    {
        $id = (string) Str::uuid();
        $store->touchSession($id, $visitor, 'test-site', ['started_at' => $startedAt, 'is_bot' => $isBot]);

        return $id;
    }
}
