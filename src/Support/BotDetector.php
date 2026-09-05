<?php

namespace SergeyBruhin\Analytics\Support;

use Jenssegers\Agent\Agent;

/**
 * Bot-flagged hits are still stored (is_bot = true on the session), never
 * silently dropped, so exclusion rate stays auditable instead of invisible.
 */
class BotDetector
{
    /**
     * jenssegers/agent's own list misses several common headless/scripted
     * clients — this is a small supplement, not a replacement.
     *
     * @var list<string>
     */
    protected array $uaBlocklist = [
        'HeadlessChrome',
        'python-requests',
        'curl/',
        'Scrapy',
        'PhantomJS',
        'Puppeteer',
        'Selenium',
        'wget',
        'axios/',
        'node-fetch',
    ];

    public function isBot(string $userAgent): bool
    {
        if (trim($userAgent) === '') {
            return true;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        if ($agent->isRobot()) {
            return true;
        }

        foreach ($this->uaBlocklist as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{browser: ?string, os: ?string, device_type: string}
     */
    public function parse(string $userAgent): array
    {
        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return [
            'browser' => $agent->browser() ?: null,
            'os' => $agent->platform() ?: null,
            'device_type' => match (true) {
                $agent->isTablet() => 'tablet',
                $agent->isMobile() => 'mobile',
                default => 'desktop',
            },
        ];
    }
}
