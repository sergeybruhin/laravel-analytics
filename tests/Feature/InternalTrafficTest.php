<?php

namespace SergeyBruhin\Analytics\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SergeyBruhin\Analytics\Analytics;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Session;
use SergeyBruhin\Analytics\Drivers\Postgres\Models\Visitor;
use SergeyBruhin\Analytics\Tests\TestCase;

class InternalTrafficTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['laravel-analytics.queue_connection' => 'sync']);
    }

    protected function tearDown(): void
    {
        Analytics::resolveInternalUsing(null);
        parent::tearDown();
    }

    private function collect(string $visitorId, ?string $sessionId = null, array $headers = []): string
    {
        $sessionId ??= (string) Str::uuid();

        $this->postJson('/api/analytics/collect', [
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'landing' => true,
            'hits' => [['type' => 'pageview', 'url' => 'https://example.test/', 'path' => '/']],
        ], $headers)->assertStatus(202);

        return $sessionId;
    }

    public function test_traffic_is_external_without_a_resolver(): void
    {
        $visitor = (string) Str::uuid();
        $session = $this->collect($visitor);

        $this->assertFalse(Visitor::find($visitor)->is_internal);
        $this->assertFalse(Session::find($session)->is_internal);
    }

    public function test_a_request_the_host_calls_internal_flags_visitor_and_session(): void
    {
        Analytics::resolveInternalUsing(fn (Request $request) => $request->hasHeader('X-Staff'));
        $visitor = (string) Str::uuid();

        $session = $this->collect($visitor, headers: ['X-Staff' => '1']);

        $this->assertTrue(Visitor::find($visitor)->is_internal);
        $this->assertTrue(Session::find($session)->is_internal);
    }

    public function test_an_internal_visitor_stays_internal_in_later_sessions(): void
    {
        Analytics::resolveInternalUsing(fn (Request $request) => $request->hasHeader('X-Staff'));
        $visitor = (string) Str::uuid();
        $this->collect($visitor, headers: ['X-Staff' => '1']);

        // Signed out of the admin panel, new tab: the resolver says no now.
        $later = $this->collect($visitor);

        $this->assertTrue(Session::find($later)->is_internal);
    }

    public function test_marking_a_visitor_flags_its_existing_sessions_and_can_be_undone(): void
    {
        $visitor = (string) Str::uuid();
        $first = $this->collect($visitor);
        $second = $this->collect($visitor);

        app(Analytics::class)->markInternal($visitor);

        $this->assertTrue(app(Analytics::class)->isInternal($visitor));
        $this->assertTrue(Session::find($first)->is_internal);
        $this->assertTrue(Session::find($second)->is_internal);

        app(Analytics::class)->unmarkInternal($visitor);

        $this->assertFalse(Visitor::find($visitor)->is_internal);
        $this->assertFalse(Session::find($first)->is_internal);
    }

    public function test_several_visitors_can_share_one_external_id_and_traits_survive_a_bare_identify(): void
    {
        $analytics = app(Analytics::class);
        [$phone, $laptop] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->collect($phone);
        $this->collect($laptop);

        $analytics->identify($phone, 'customer:42', ['plan' => 'vip']);
        $analytics->identify($laptop, 'customer:42');
        $analytics->identify($phone, 'customer:42');

        $this->assertEqualsCanonicalizing([$phone, $laptop], $analytics->visitorIdsFor('customer:42'));
        $this->assertSame(['plan' => 'vip'], Visitor::find($phone)->traits);
    }
}
