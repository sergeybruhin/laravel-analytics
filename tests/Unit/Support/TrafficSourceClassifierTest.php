<?php

namespace SergeyBruhin\Analytics\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SergeyBruhin\Analytics\Support\TrafficSourceClassifier;

class TrafficSourceClassifierTest extends TestCase
{
    /**
     * @return array<string, array{0: array, 1: array, 2: ?string, 3: string, 4: ?string}>
     */
    public static function cases(): array
    {
        return [
            'yandex direct, tagged' => [['source' => 'yandex', 'medium' => 'cpc'], [], null, 'paid', 'yandex'],
            'utm_ prefixed keys' => [['utm_source' => 'google', 'utm_medium' => 'CPC'], [], null, 'paid', 'google'],
            'newsletter' => [['source' => 'unisender', 'medium' => 'email'], [], null, 'email', 'unisender'],
            'tagged social post' => [['source' => 'telegram', 'medium' => 'social'], [], null, 'social', 'telegram'],
            'source is a known network' => [['source' => 'vk'], [], null, 'social', 'vk'],
            'blogger with a partner tag' => [['source' => 'blogger_anna', 'medium' => 'partner'], [], null, 'referral', 'blogger_anna'],
            'unknown tag' => [['source' => 'qr', 'medium' => 'offline'], [], null, 'campaign', 'qr'],
            'medium only keeps the referrer as source' => [['medium' => 'cpc'], [], 'https://yandex.ru/search', 'paid', 'yandex.ru'],
            'untagged yclid' => [[], ['yclid' => '123'], 'https://yandex.ru/', 'paid', 'yandex'],
            'untagged gclid' => [[], ['gclid' => 'abc'], null, 'paid', 'google'],
            'ysclid is not an ad click' => [[], ['ysclid' => 'x'], 'https://ya.ru/', 'organic', 'yandex'],
            'yandex search' => [[], [], 'https://yandex.ru/search/?text=peptides', 'organic', 'yandex'],
            'google country domain' => [[], [], 'https://www.google.de/', 'organic', 'google'],
            'yandex country domain' => [[], [], 'https://yandex.uz/', 'organic', 'yandex'],
            'vk' => [[], [], 'https://m.vk.com/wall-1', 'social', 'vk'],
            'telegram' => [[], [], 'https://t.me/peptera', 'social', 'telegram'],
            'dzen' => [[], [], 'https://dzen.ru/a/xyz', 'social', 'dzen'],
            'a blog' => [[], [], 'https://www.someblog.ru/post', 'referral', 'someblog.ru'],
            'own site is navigation' => [[], [], 'https://peptera.ru/catalog', 'direct', null],
            'own subdomain is navigation' => [[], [], 'https://shop.peptera.ru/', 'direct', null],
            'nothing' => [[], [], null, 'direct', null],
            'blank referrer' => [[], [], '  ', 'direct', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_classifies(array $utm, array $clickIds, ?string $referrer, string $channel, ?string $source): void
    {
        $result = (new TrafficSourceClassifier)->classify($utm, $clickIds, $referrer, ['peptera.ru']);

        $this->assertSame($channel, $result->channel);
        $this->assertSame($source, $result->source);
    }

    public function test_label_reads_naturally(): void
    {
        $classifier = new TrafficSourceClassifier;

        $this->assertSame('yandex / organic', $classifier->classify(referrer: 'https://yandex.ru/')->label());
        $this->assertSame('direct', $classifier->classify()->label());
    }
}
