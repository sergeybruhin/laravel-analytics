<?php

namespace SergeyBruhin\Analytics\Support;

/**
 * Where a visit came from, reduced to a channel (the kind of traffic) and a
 * source (who sent it). Produced by TrafficSourceClassifier; plain values so a
 * host can store them on its own records (an order, a customer).
 */
final class TrafficSource
{
    public const PAID = 'paid';

    public const ORGANIC = 'organic';

    public const SOCIAL = 'social';

    public const EMAIL = 'email';

    public const REFERRAL = 'referral';

    /** Tagged with utm_* the classifier could not place in a channel above. */
    public const CAMPAIGN = 'campaign';

    public const DIRECT = 'direct';

    public function __construct(
        public readonly string $channel,
        public readonly ?string $source = null,
        public readonly ?string $medium = null,
        public readonly ?string $campaign = null,
    ) {}

    /** «yandex / paid», «direct» — for a table cell or a log line. */
    public function label(): string
    {
        return $this->source === null ? $this->channel : "{$this->source} / {$this->channel}";
    }

    /**
     * @return array{channel: string, source: ?string, medium: ?string, campaign: ?string}
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'source' => $this->source,
            'medium' => $this->medium,
            'campaign' => $this->campaign,
        ];
    }
}
