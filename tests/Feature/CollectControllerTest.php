<?php

namespace SergeyBruhin\Analytics\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use SergeyBruhin\Analytics\Jobs\RecordAnalyticsHitJob;
use SergeyBruhin\Analytics\Tests\TestCase;

class CollectControllerTest extends TestCase
{
    public function test_valid_payload_is_accepted_and_queues_a_job(): void
    {
        Queue::fake();

        $this->postJson('/api/analytics/collect', $this->validPayload())
            ->assertStatus(202);

        Queue::assertPushed(RecordAnalyticsHitJob::class);
    }

    public function test_batch_larger_than_the_cap_is_rejected(): void
    {
        Queue::fake();

        $payload = $this->validPayload();
        $payload['hits'] = array_fill(0, 21, $payload['hits'][0]);

        $this->postJson('/api/analytics/collect', $payload)->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_malformed_event_name_is_rejected(): void
    {
        Queue::fake();

        $payload = $this->validPayload();
        $payload['hits'] = [[
            'type' => 'event',
            'name' => 'Not A Valid Name!',
        ]];

        $this->postJson('/api/analytics/collect', $payload)->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_oversized_event_properties_are_rejected(): void
    {
        Queue::fake();

        $payload = $this->validPayload();
        $payload['hits'] = [[
            'type' => 'event',
            'name' => 'big_event',
            'properties' => ['blob' => str_repeat('a', 5000)],
        ]];

        $this->postJson('/api/analytics/collect', $payload)->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_mismatched_origin_is_silently_accepted_but_not_queued(): void
    {
        Queue::fake();

        $this->postJson('/api/analytics/collect', $this->validPayload(), [
            'Origin' => 'https://attacker.example',
        ])->assertStatus(202);

        Queue::assertNothingPushed();
    }

    public function test_matching_origin_is_queued(): void
    {
        Queue::fake();

        $this->postJson('/api/analytics/collect', $this->validPayload(), [
            'Origin' => 'https://example.test',
        ])->assertStatus(202);

        Queue::assertPushed(RecordAnalyticsHitJob::class);
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
