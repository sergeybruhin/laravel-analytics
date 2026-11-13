<?php

namespace SergeyBruhin\Analytics\Support;

/**
 * Turns the raw attribution a visit carried — utm_* tags, ad click ids, the
 * referrer — into one TrafficSource, using the precedence an ad platform
 * report would:
 *
 *   1. explicit utm_* tags (the marketer said what this is),
 *   2. an ad click id (yclid, gclid, …) — paid even when untagged,
 *   3. the referrer's host: a search engine is organic, a social network is
 *      social, anything else is a referral,
 *   4. nothing at all — direct.
 *
 * A referrer from the site itself (ownHosts) is navigation, not a source, and
 * counts as direct. Pure and stateless: safe to call per row in a backfill.
 */
final class TrafficSourceClassifier
{
    /** utm_medium values meaning «we paid for this click». */
    private const PAID_MEDIUMS = ['cpc', 'ppc', 'cpm', 'cpa', 'cpv', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'display', 'banner', 'retargeting', 'paid_social', 'paidsocial'];

    private const EMAIL_MEDIUMS = ['email', 'e-mail', 'newsletter', 'mailing', 'rassylka'];

    private const SOCIAL_MEDIUMS = ['social', 'smm', 'social-network', 'social_network', 'sm'];

    private const ORGANIC_MEDIUMS = ['organic', 'seo'];

    private const REFERRAL_MEDIUMS = ['referral', 'ref', 'partner', 'affiliate'];

    /** click id parameter => ad platform. */
    private const PAID_CLICK_IDS = [
        'yclid' => 'yandex',
        'ysclid' => null, // Yandex's own search tracking, not an ad click: see referrer.
        'gclid' => 'google',
        'gbraid' => 'google',
        'wbraid' => 'google',
        'msclkid' => 'bing',
        'ttclid' => 'tiktok',
        'vkclid' => 'vk',
    ];

    /** host suffix => engine name. */
    private const SEARCH_ENGINES = [
        'yandex.ru' => 'yandex', 'yandex.com' => 'yandex', 'yandex.by' => 'yandex', 'yandex.kz' => 'yandex', 'ya.ru' => 'yandex',
        'google.com' => 'google', 'google.ru' => 'google',
        'bing.com' => 'bing', 'duckduckgo.com' => 'duckduckgo', 'go.mail.ru' => 'mail.ru',
        'search.yahoo.com' => 'yahoo', 'rambler.ru' => 'rambler', 'nova.rambler.ru' => 'rambler',
        'search.brave.com' => 'brave', 'ecosia.org' => 'ecosia', 'perplexity.ai' => 'perplexity',
        'chatgpt.com' => 'chatgpt', 'alice.yandex.ru' => 'alice',
    ];

    /** host suffix => network name. */
    private const SOCIAL_NETWORKS = [
        'vk.com' => 'vk', 'vk.ru' => 'vk', 'm.vk.com' => 'vk', 'ok.ru' => 'ok',
        't.me' => 'telegram', 'telegram.org' => 'telegram', 'web.telegram.org' => 'telegram',
        'dzen.ru' => 'dzen', 'zen.yandex.ru' => 'dzen',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'rutube.ru' => 'rutube',
        'instagram.com' => 'instagram', 'l.instagram.com' => 'instagram',
        'facebook.com' => 'facebook', 'l.facebook.com' => 'facebook', 'm.facebook.com' => 'facebook',
        'tiktok.com' => 'tiktok', 'pinterest.com' => 'pinterest', 'pinterest.ru' => 'pinterest',
        'x.com' => 'x', 'twitter.com' => 'x', 't.co' => 'x',
        'linkedin.com' => 'linkedin', 'whatsapp.com' => 'whatsapp', 'wa.me' => 'whatsapp',
        'reddit.com' => 'reddit', 'pikabu.ru' => 'pikabu', 'threads.net' => 'threads',
    ];

    /**
     * @param  array<string, ?string>  $utm  keys source|medium|campaign (with or without a utm_ prefix)
     * @param  array<string, ?string>  $clickIds
     * @param  list<string>  $ownHosts  the site's own hosts; a referrer from these is navigation
     */
    public function classify(array $utm = [], array $clickIds = [], ?string $referrer = null, array $ownHosts = []): TrafficSource
    {
        $source = $this->clean($utm['source'] ?? $utm['utm_source'] ?? null);
        $medium = $this->clean($utm['medium'] ?? $utm['utm_medium'] ?? null);
        $campaign = $this->clean($utm['campaign'] ?? $utm['utm_campaign'] ?? null);

        if ($source !== null || $medium !== null) {
            return new TrafficSource($this->channelForTags($source, $medium), $source ?? $this->sourceFromReferrer($referrer, $ownHosts), $medium, $campaign);
        }

        foreach (self::PAID_CLICK_IDS as $param => $platform) {
            if ($platform !== null && ! empty($clickIds[$param])) {
                return new TrafficSource(TrafficSource::PAID, $platform, null, $campaign);
            }
        }

        $host = $this->host($referrer);

        if ($host === null || $this->matches($host, $ownHosts) !== null) {
            return new TrafficSource(TrafficSource::DIRECT);
        }

        if (($engine = $this->lookup($host, self::SEARCH_ENGINES)) !== null) {
            return new TrafficSource(TrafficSource::ORGANIC, $engine);
        }

        if (($network = $this->lookup($host, self::SOCIAL_NETWORKS)) !== null) {
            return new TrafficSource(TrafficSource::SOCIAL, $network);
        }

        return new TrafficSource(TrafficSource::REFERRAL, $host);
    }

    private function channelForTags(?string $source, ?string $medium): string
    {
        $medium = $medium === null ? null : mb_strtolower($medium);

        return match (true) {
            $medium !== null && in_array($medium, self::PAID_MEDIUMS, true) => TrafficSource::PAID,
            $medium !== null && in_array($medium, self::EMAIL_MEDIUMS, true) => TrafficSource::EMAIL,
            $medium !== null && in_array($medium, self::SOCIAL_MEDIUMS, true) => TrafficSource::SOCIAL,
            $medium !== null && in_array($medium, self::ORGANIC_MEDIUMS, true) => TrafficSource::ORGANIC,
            $medium !== null && in_array($medium, self::REFERRAL_MEDIUMS, true) => TrafficSource::REFERRAL,
            $source !== null && in_array(mb_strtolower($source), array_values(self::SOCIAL_NETWORKS), true) => TrafficSource::SOCIAL,
            default => TrafficSource::CAMPAIGN,
        };
    }

    /** @param list<string> $ownHosts */
    private function sourceFromReferrer(?string $referrer, array $ownHosts): ?string
    {
        $host = $this->host($referrer);

        return $host === null || $this->matches($host, $ownHosts) !== null ? null : $host;
    }

    private function host(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = mb_strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Google and Yandex answer from dozens of country domains (google.de,
     * yandex.uz); match them by their second-level label before the table.
     *
     * @param  array<string, string>  $table
     */
    private function lookup(string $host, array $table): ?string
    {
        if (($hit = $this->matches($host, array_keys($table))) !== null) {
            return $table[$hit];
        }

        if ($table === self::SEARCH_ENGINES && preg_match('/(^|\.)(google|yandex)\.[a-z.]{2,6}$/', $host, $m)) {
            return $m[2];
        }

        return null;
    }

    /**
     * The entry of $suffixes that $host is, or is a subdomain of.
     *
     * @param  list<string>  $suffixes
     */
    private function matches(string $host, array $suffixes): ?string
    {
        foreach ($suffixes as $suffix) {
            $suffix = mb_strtolower($suffix);
            $suffix = str_starts_with($suffix, 'www.') ? substr($suffix, 4) : $suffix;

            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
