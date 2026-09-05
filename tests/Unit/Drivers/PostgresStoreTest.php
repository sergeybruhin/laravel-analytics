<?php

namespace SergeyBruhin\Analytics\Tests\Unit\Drivers;

use Illuminate\Support\Str;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Event;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Session;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Visitor;
use SergeyBruhin\Analytics\Drivers\Postgres\PostgresStore;
use SergeyBruhin\Analytics\Tests\TestCase;

class PostgresStoreTest extends TestCase
{
    private PostgresStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new PostgresStore('analytics');
    }

    public function test_upsert_visitor_sets_first_touch_fields_only_once(): void
    {
        $id = (string) Str::uuid();

        $this->store->upsertVisitor($id, 'test-site', [
            'first_utm_source' => 'google',
            'first_landing_url' => 'https://example.test/landing',
        ]);

        // A returning visit with different attribution must not overwrite
        // the original first-touch fields.
        $this->store->upsertVisitor($id, 'test-site', [
            'first_utm_source' => 'newsletter',
        ]);

        $visitor = Visitor::on('analytics')->find($id);

        $this->assertSame('google', $visitor->first_utm_source);
        $this->assertSame('https://example.test/landing', $visitor->first_landing_url);
    }

    public function test_touch_session_applies_last_non_direct_click_wins(): void
    {
        $visitorId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $this->store->upsertVisitor($visitorId, 'test-site', []);
        $this->store->touchSession($sessionId, $visitorId, 'test-site', [
            'utm_source' => 'google',
            'exit_url' => 'https://example.test/a',
        ]);

        // A later, direct hit (no utm keys in $attributes) only updates
        // bookkeeping fields — it must not clear the earlier attribution.
        $this->store->touchSession($sessionId, $visitorId, 'test-site', [
            'exit_url' => 'https://example.test/b',
        ]);

        $session = Session::on('analytics')->find($sessionId);

        $this->assertSame('google', $session->utm_source);
        $this->assertSame('https://example.test/b', $session->exit_url);
    }

    public function test_record_event_is_idempotent_on_dedup_key(): void
    {
        $visitorId = (string) Str::uuid();
        $this->store->upsertVisitor($visitorId, 'test-site', []);

        $attributes = [
            'visitor_id' => $visitorId,
            'session_id' => null,
            'site' => 'test-site',
            'name' => 'purchase',
            'properties' => [],
            'value' => 42.5,
            'dedup_key' => 'order-123',
            'created_at' => now(),
        ];

        $this->store->recordEvent($attributes);
        $this->store->recordEvent($attributes); // simulates a retried beacon

        $this->assertSame(1, Event::on('analytics')->where('dedup_key', 'order-123')->count());
    }

    public function test_identify_links_visitor_to_an_external_id(): void
    {
        $visitorId = (string) Str::uuid();
        $this->store->upsertVisitor($visitorId, 'test-site', []);

        $this->store->identify($visitorId, 'customer-42', ['plan' => 'pro']);

        $visitor = Visitor::on('analytics')->find($visitorId);

        $this->assertSame('customer-42', $visitor->external_id);
        $this->assertSame(['plan' => 'pro'], $visitor->traits);
    }
}
