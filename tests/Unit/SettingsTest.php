<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Admin\SettingsSanitizer;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Tests\Support\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private const MARKETS = ['match_result' => 'Match Result', 'btts' => 'Both Teams To Score'];

    private SettingsSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SettingsSanitizer(
            [
                ['id' => 'bet365', 'name' => 'bet365', 'url' => 'https://www.bet365.com/'],
                ['id' => 'unibet', 'name' => 'Unibet', 'url' => 'https://www.unibet.com/'],
                ['id' => 'betway', 'name' => 'Betway', 'url' => 'https://www.betway.com/'],
            ],
            self::MARKETS
        );
    }

    public function testBookmakersKeepSubmittedOrderAndAppendMissingAsDisabled(): void
    {
        [$rows, $warnings] = $this->sanitizer->bookmakers([
            'unibet' => ['name' => 'Unibet UK', 'url' => 'https://aff.example.com/unibet?ref=1', 'enabled' => '1'],
            'bet365' => ['name' => '', 'url' => '', 'logo' => 'https://cdn.example.com/b.png'],
        ]);

        self::assertSame([], $warnings);
        self::assertSame(['unibet', 'bet365', 'betway'], array_column($rows, 'id'));
        self::assertSame('Unibet UK', $rows[0]['name']);
        self::assertTrue($rows[0]['enabled']);
        self::assertSame('bet365', $rows[1]['name'], 'empty name falls back to default');
        self::assertSame('https://www.bet365.com/', $rows[1]['url'], 'empty link falls back to default');
        self::assertFalse($rows[1]['enabled'], 'unchecked checkbox is not submitted');
        self::assertSame('https://cdn.example.com/b.png', $rows[1]['logo']);
        self::assertFalse($rows[2]['enabled']);
    }

    public function testRejectsDangerousUrlsAndUnknownIds(): void
    {
        [$rows, $warnings] = $this->sanitizer->bookmakers([
            'bet365' => [
                'name' => '<b>bet</b>365 <script>x</script>',
                'url' => 'javascript:alert(1)',
                'logo' => 'data:image/png;base64,AAA',
                'enabled' => '1',
            ],
            'evil' => ['name' => 'Injected', 'url' => 'https://evil.example.com', 'enabled' => '1'],
        ]);

        self::assertNotContains('evil', array_column($rows, 'id'));
        self::assertSame('bet365 x', $rows[0]['name']);
        self::assertSame('https://www.bet365.com/', $rows[0]['url']);
        self::assertNull($rows[0]['logo']);
        self::assertCount(2, $warnings);
    }

    public function testBookmakerSanitizingIsIdempotent(): void
    {
        [$once] = $this->sanitizer->bookmakers([
            'betway' => ['name' => 'Betway', 'url' => 'https://aff.example.com/bw', 'enabled' => '1'],
        ]);
        [$twice] = $this->sanitizer->bookmakers($once);

        self::assertSame($once, $twice);
    }

    public function testMarketsKeepCatalogueOrderAndNeverEndUpEmpty(): void
    {
        self::assertSame([['match_result', 'btts'], []], $this->sanitizer->markets(['btts', 'match_result', 'corners']));
        self::assertSame([['btts'], []], $this->sanitizer->markets(['btts']));

        [$keys, $warnings] = $this->sanitizer->markets([]);
        self::assertSame(['match_result', 'btts'], $keys);
        self::assertCount(1, $warnings);
    }

    public function testSourceMustBeRegistered(): void
    {
        self::assertSame('fixture', $this->sanitizer->source('scraper', ['fixture'], 'fixture'));
        self::assertSame('fixture', $this->sanitizer->source(['x'], ['fixture'], 'fixture'));
        self::assertSame('api', $this->sanitizer->source('api', ['fixture', 'api'], 'fixture'));
    }

    public function testMarketRepositoryDefaultsToAllAndRespectsSettings(): void
    {
        $store = new InMemoryStore();
        $markets = new MarketRepository($store, self::MARKETS);

        self::assertSame(self::MARKETS, $markets->enabled());

        $store->set(MarketRepository::OPTION, ['btts']);
        self::assertSame(['btts' => 'Both Teams To Score'], $markets->enabled());
        self::assertFalse($markets->isEnabled('match_result'));
    }
}
