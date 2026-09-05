<?php

namespace SergeyBruhin\Analytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergeyBruhin\Analytics\Contracts\AnalyticsStore;
use SergeyBruhin\Analytics\Support\BotDetector;
use SergeyBruhin\Analytics\Support\IpAnonymizer;

/**
 * All storage writes happen here, off the request thread, so a slow or
 * unavailable analytics store never blocks or slows any request — checkout
 * included. Analytics is best-effort: a short retry budget, not the
 * aggressive backoff a checkout-critical job would get.
 */
class RecordAnalyticsHitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $backoff = 15;

    /**
     * @param  array<string, mixed>  $payload  the validated CollectRequest payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $site,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {}

    public function handle(AnalyticsStore $store, BotDetector $bots, IpAnonymizer $ips): void
    {
        $visitorId = $this->payload['visitor_id'];
        $sessionId = $this->payload['session_id'];

        $parsedAgent = $bots->parse($this->userAgent);
        $isBot = $bots->isBot($this->userAgent);
        $ipTruncated = config('laravel-analytics.store_ip', true) ? $ips->truncate($this->ip) : null;

        $utm = array_filter($this->payload['utm'] ?? []);
        $clickIds = array_filter($this->payload['click_ids'] ?? []);
        $hasAttribution = $utm !== [] || $clickIds !== [];
        $landingUrl = $this->firstPageviewUrl();

        $store->upsertVisitor($visitorId, $this->site, array_filter([
            'first_landing_url' => ! empty($this->payload['landing']) ? $landingUrl : null,
            'first_referrer' => $this->payload['referrer'] ?? null,
            'first_utm_source' => $utm['source'] ?? null,
            'first_utm_medium' => $utm['medium'] ?? null,
            'first_utm_campaign' => $utm['campaign'] ?? null,
            'first_utm_content' => $utm['content'] ?? null,
            'first_utm_term' => $utm['term'] ?? null,
            'first_click_ids' => $clickIds !== [] ? $clickIds : null,
        ], static fn ($value) => $value !== null));

        $sessionAttributes = array_filter([
            'exit_url' => $this->lastPageviewUrl(),
            'device_type' => $parsedAgent['device_type'],
            'browser' => $parsedAgent['browser'],
            'os' => $parsedAgent['os'],
            'is_bot' => $isBot,
            'ip_truncated' => $ipTruncated,
        ], static fn ($value) => $value !== null);

        // Last-non-direct-click-wins: only overwrite utm_*/click_ids when
        // this hit actually carried attribution, matching the semantics
        // already implemented client-side in plugins/utm-meta.ts.
        if ($hasAttribution) {
            $sessionAttributes['utm_source'] = $utm['source'] ?? null;
            $sessionAttributes['utm_medium'] = $utm['medium'] ?? null;
            $sessionAttributes['utm_campaign'] = $utm['campaign'] ?? null;
            $sessionAttributes['utm_content'] = $utm['content'] ?? null;
            $sessionAttributes['utm_term'] = $utm['term'] ?? null;
            $sessionAttributes['click_ids'] = $clickIds !== [] ? $clickIds : null;
        }

        if (! empty($this->payload['landing'])) {
            $sessionAttributes['landing_url'] = $landingUrl;
            $sessionAttributes['referrer'] = $this->payload['referrer'] ?? null;
        }

        $store->touchSession($sessionId, $visitorId, $this->site, $sessionAttributes);

        if (! empty($this->payload['identify']['external_id'])) {
            $store->identify(
                $visitorId,
                $this->payload['identify']['external_id'],
                $this->payload['identify']['traits'] ?? [],
            );
        }

        foreach ($this->payload['hits'] as $hit) {
            if ($hit['type'] === 'pageview') {
                $store->recordPageview([
                    'session_id' => $sessionId,
                    'visitor_id' => $visitorId,
                    'site' => $this->site,
                    'url' => $hit['url'],
                    'path' => $hit['path'] ?? (parse_url($hit['url'], PHP_URL_PATH) ?: '/'),
                    'referrer' => $this->payload['referrer'] ?? null,
                    'title' => $hit['title'] ?? null,
                    'duration_ms' => $hit['duration_ms'] ?? null,
                    'created_at' => now(),
                ]);

                continue;
            }

            $store->recordEvent([
                'session_id' => $sessionId,
                'visitor_id' => $visitorId,
                'site' => $this->site,
                'name' => $hit['name'],
                'properties' => $hit['properties'] ?? [],
                'value' => $hit['value'] ?? null,
                'dedup_key' => $hit['dedup_key'] ?? null,
                'created_at' => now(),
            ]);
        }
    }

    protected function firstPageviewUrl(): ?string
    {
        foreach ($this->payload['hits'] as $hit) {
            if ($hit['type'] === 'pageview') {
                return $hit['url'];
            }
        }

        return null;
    }

    protected function lastPageviewUrl(): ?string
    {
        $url = null;

        foreach ($this->payload['hits'] as $hit) {
            if ($hit['type'] === 'pageview') {
                $url = $hit['url'];
            }
        }

        return $url;
    }
}
