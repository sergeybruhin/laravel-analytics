<?php

namespace SergeyBruhin\Analytics\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use SergeyBruhin\Analytics\Tests\TestCase;

/**
 * The collect endpoint is the one endpoint a visitor hits simply by browsing —
 * the reference tracker flushes on a five-second timer, per tab. Its limit has
 * to be sized for that, and it must never be charged to a counter the host
 * application shares with routes of its own.
 */
class CollectRateLimitTest extends TestCase
{
    /**
     * No migrations, and no database at all.
     *
     * Every request here is either refused by the limiter or queued through
     * Queue::fake(), so nothing reaches a store. Skipping the migrations keeps
     * the one part of ingestion that can break a host application testable on a
     * machine with no Postgres running.
     */
    protected function defineDatabaseMigrations(): void
    {
        //
    }

    public function test_it_throttles_a_single_visitor_at_the_configured_ceiling(): void
    {
        Queue::fake();

        config(['laravel-analytics.rate_limit.per_visitor' => 2]);

        $payload = $this->validPayload();

        $this->postJson('/api/analytics/collect', $payload)->assertStatus(202);
        $this->postJson('/api/analytics/collect', $payload)->assertStatus(202);
        $this->postJson('/api/analytics/collect', $payload)->assertStatus(429);
    }

    public function test_one_visitor_cannot_exhaust_anothers_budget(): void
    {
        Queue::fake();

        // Two people behind one NAT — an office, a school, a carrier gateway.
        // Keying on the IP alone is how the first of them silently switches
        // analytics off for everyone else in the building.
        config(['laravel-analytics.rate_limit.per_visitor' => 2]);

        $first = $this->validPayload();
        $second = $this->validPayload();

        $this->postJson('/api/analytics/collect', $first)->assertStatus(202);
        $this->postJson('/api/analytics/collect', $first)->assertStatus(202);
        $this->postJson('/api/analytics/collect', $first)->assertStatus(429);

        $this->postJson('/api/analytics/collect', $second)->assertStatus(202);
    }

    public function test_the_per_ip_backstop_survives_a_rotated_visitor_id(): void
    {
        Queue::fake();

        // visitor_id is client-generated, so a caller can mint a fresh one per
        // request. The backstop is what makes that pointless.
        config([
            'laravel-analytics.rate_limit.per_visitor' => 1000,
            'laravel-analytics.rate_limit.per_ip' => 3,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/analytics/collect', $this->validPayload())->assertStatus(202);
        }

        $this->postJson('/api/analytics/collect', $this->validPayload())->assertStatus(429);
    }

    public function test_it_can_be_switched_off_entirely(): void
    {
        Queue::fake();

        // For an install that throttles at the edge instead (a CDN, a WAF).
        config([
            'laravel-analytics.rate_limit.per_visitor' => 0,
            'laravel-analytics.rate_limit.per_ip' => 0,
        ]);

        $payload = $this->validPayload();

        for ($i = 0; $i < 50; $i++) {
            $this->postJson('/api/analytics/collect', $payload)->assertStatus(202);
        }
    }

    public function test_the_default_ceiling_clears_a_normal_browsing_session(): void
    {
        Queue::fake();

        // The reference tracker flushes every five seconds and on every
        // page hide — around twelve calls a minute per tab. The default has to
        // leave room for a visitor with several tabs open without ever being
        // the thing that answers 429.
        $payload = $this->validPayload();

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/analytics/collect', $payload)->assertStatus(202);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'landing' => true,
            'hits' => [[
                'type' => 'pageview',
                'url' => 'https://example.test/',
                'path' => '/',
                'title' => 'Home',
            ]],
        ];
    }
}
