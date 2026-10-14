<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Block\OddsTableBlock;
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\FixtureSource;
use BestOddsTable\Tests\Support\InMemoryStore;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;
use PHPUnit\Framework\TestCase;

final class OddsTableBlockTest extends TestCase
{
    /** Before every fixture kick-off, so nothing counts as finished. */
    private const NOW = 1_791_000_000;

    private const ATTRIBUTES = ['eventId' => 'ars-che-2026-10-18', 'marketKey' => 'match_result'];

    private InMemoryStore $store;
    private OddsRepository $odds;
    private OddsTableBlock $block;

    protected function setUp(): void
    {
        $converter = new OddsConverter();
        $this->store = new InMemoryStore();
        $this->odds = new OddsRepository(
            new InMemoryStore(),
            new InMemoryStore(),
            new EventFactory($converter, OddsFormat::Decimal)
        );

        $this->block = new OddsTableBlock(
            $this->odds,
            new BookmakerRepository($this->store, require __DIR__ . '/../../config/bookmakers.php'),
            new MarketRepository($this->store, require __DIR__ . '/../../config/markets.php'),
            new OddsTablePresenter($converter),
        );
    }

    private function cacheFixtureOdds(): void
    {
        $fetcher = new OddsFetcher(
            new FixtureSource(__DIR__ . '/../../fixtures/odds.json', new EventFactory(new OddsConverter())),
            new WatchList(new InMemoryStore()),
            $this->odds,
            new RefreshPolicy(),
            new InMemoryStore(),
        );
        $fetcher->fetchPair(new WatchedMarket(self::ATTRIBUTES['eventId'], self::ATTRIBUTES['marketKey']), self::NOW);
    }

    public function testUnconfiguredOrInvalidBlockRendersNothing(): void
    {
        self::assertNull($this->block->viewModel([]));
        self::assertNull($this->block->viewModel(['eventId' => '../x', 'marketKey' => 'match_result']));
    }

    public function testNothingIsRenderedBeforeOddsAreCached(): void
    {
        self::assertNull($this->block->viewModel(self::ATTRIBUTES));
    }

    public function testMarketDisabledInSettingsIsNotRendered(): void
    {
        $this->cacheFixtureOdds();
        $this->store->set(MarketRepository::OPTION, ['btts']);

        self::assertNull($this->block->viewModel(self::ATTRIBUTES));
    }

    public function testRendersSelectedBookmakersInOrderWithEveryFormat(): void
    {
        $this->cacheFixtureOdds();

        $view = $this->block->viewModel(self::ATTRIBUTES + [
            'bookmakers' => ['unibet', 'no-such-bookmaker', 'bet365', ['not a string']],
            'format' => 'fractional',
        ]);

        self::assertNotNull($view);
        self::assertSame('fractional', $view['format']);
        self::assertSame(['unibet', 'bet365'], array_column(array_column($view['rows'], 'bookmaker'), 'id'));

        $cell = $view['rows'][0]['prices']['home'];
        self::assertSame(['decimal', 'fractional', 'american'], array_keys($cell['formats']));
        self::assertSame($cell['value'], $cell['formats']['fractional']);
    }

    public function testUnknownFormatFallsBackToDecimal(): void
    {
        $this->cacheFixtureOdds();

        self::assertSame('decimal', $this->block->viewModel(self::ATTRIBUTES + ['format' => '<script>'])['format']);
    }
}
