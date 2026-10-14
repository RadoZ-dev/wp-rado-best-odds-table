<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\Bookmaker;
use BestOddsTable\Domain\Event;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\FetchReport;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\FixtureSource;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Tests\Support\InMemoryStore;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;
use PHPUnit\Framework\TestCase;

/**
 * The fetch pipeline end to end: watch list -> fetcher -> repository -> presenter.
 */
final class FetchAndPresentTest extends TestCase
{
    /** Before every fixture kick-off, so nothing counts as finished. */
    private const NOW = 1_791_000_000;

    private InMemoryStore $cache;
    private InMemoryStore $durable;
    private WatchList $watchList;
    private OddsRepository $repository;
    private OddsConverter $converter;
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->cache = new InMemoryStore();
        $this->durable = new InMemoryStore();
        $this->converter = new OddsConverter();
        $this->watchList = new WatchList(new InMemoryStore());
        $this->repository = new OddsRepository(
            $this->cache,
            $this->durable,
            new EventFactory($this->converter, OddsFormat::Decimal)
        );
    }

    private function fetcher(OddsSourceInterface $source, int $batchSize = 20): OddsFetcher
    {
        return new OddsFetcher(
            $source,
            $this->watchList,
            $this->repository,
            new RefreshPolicy(),
            new InMemoryStore(),
            $batchSize,
            0,
            function (string $hook, mixed ...$args): void {
                $this->dispatched[] = [$hook, $args];
            }
        );
    }

    private function fixture(): FixtureSource
    {
        return new FixtureSource(__DIR__ . '/../../fixtures/odds.json', new EventFactory($this->converter));
    }

    public function testRunFetchesWatchedPairsAndStoresThem(): void
    {
        $pair = new WatchedMarket('ars-che-2026-10-18', 'match_result');
        $this->watchList->syncPost(1, [$pair]);

        $report = $this->fetcher($this->fixture())->run(self::NOW);

        self::assertSame(FetchReport::OK, $report->status);
        self::assertSame(1, $report->fetched);
        self::assertSame('best_odds_table_fetched', $this->dispatched[0][0]);

        $cached = $this->repository->find($pair);
        self::assertNotNull($cached);
        self::assertFalse($cached->stale);
        self::assertSame(self::NOW, $cached->fetchedAt);
        self::assertCount(10, $cached->market->bookmakerIds());

        // Second run in the same minute: nothing is due.
        self::assertSame(0, $this->fetcher($this->fixture())->run(self::NOW + 60)->fetched);
    }

    public function testBatchSizeDefersTheRest(): void
    {
        $this->watchList->syncPost(1, [
            new WatchedMarket('ars-che-2026-10-18', 'match_result'),
            new WatchedMarket('liv-mci-2026-10-18', 'match_result'),
            new WatchedMarket('rma-bar-2026-10-19', 'btts'),
        ]);

        $report = $this->fetcher($this->fixture(), 2)->run(self::NOW);

        self::assertSame(2, $report->fetched);
        self::assertSame(1, $report->deferred);
    }

    public function testCachedPricesSurviveRoundTripExactly(): void
    {
        $pair = new WatchedMarket('ars-che-2026-10-18', 'match_result');
        $this->watchList->syncPost(1, [$pair]);
        $this->fetcher($this->fixture())->run(self::NOW);

        $original = $this->fixture()->fetchEvent('ars-che-2026-10-18')?->market('match_result');
        $cached = $this->repository->find($pair)?->market;
        self::assertNotNull($original);
        self::assertNotNull($cached);

        foreach ($original->prices() as $price) {
            self::assertSame(
                $this->converter->format($price->odds, OddsFormat::Fractional),
                $this->converter->format(
                    $cached->priceFor($price->bookmakerId, $price->outcomeKey)->odds,
                    OddsFormat::Fractional
                )
            );
        }
    }

    public function testFallsBackToStaleSnapshotWhenHotCacheIsGone(): void
    {
        $pair = new WatchedMarket('ars-che-2026-10-18', 'match_result');
        $this->watchList->syncPost(1, [$pair]);
        $this->fetcher($this->fixture())->run(self::NOW);

        $this->cache->flush();

        $cached = $this->repository->find($pair);
        self::assertNotNull($cached);
        self::assertTrue($cached->stale);
    }

    public function testRateLimitStopsTheRunAndBacksOff(): void
    {
        $limited = new class implements OddsSourceInterface {
            public int $calls = 0;

            public function id(): string
            {
                return 'limited';
            }

            public function label(): string
            {
                return 'Limited';
            }

            public function fetchEvents(array $marketKeys = []): array
            {
                return [];
            }

            public function fetchEvent(string $eventId, array $marketKeys = []): ?Event
            {
                $this->calls++;
                throw SourceException::rateLimited('limited', 600);
            }
        };

        $this->watchList->syncPost(1, [new WatchedMarket('a', 'match_result'), new WatchedMarket('b', 'match_result')]);
        $fetcher = $this->fetcher($limited);

        $report = $fetcher->run(self::NOW);
        self::assertSame(FetchReport::RATE_LIMITED, $report->status);
        self::assertSame(1, $limited->calls, 'stops after the first 429');
        self::assertSame(1, $report->deferred);
        self::assertSame('best_odds_table_fetch_failed', $this->dispatched[0][0]);

        self::assertSame(FetchReport::BACKING_OFF, $fetcher->run(self::NOW + 300)->status);
        self::assertSame(1, $limited->calls);
    }

    public function testMissingEventIsRecordedNotRetriedEveryRun(): void
    {
        $pair = new WatchedMarket('no-such-event', 'match_result');
        $this->watchList->syncPost(1, [$pair]);
        $fetcher = $this->fetcher($this->fixture());

        self::assertSame(1, $fetcher->run(self::NOW)->failed);
        self::assertSame('best_odds_table_market_missing', $this->dispatched[0][0]);
        self::assertSame(0, $fetcher->run(self::NOW + 60)->failed, 'waits for the next interval');
    }

    public function testPresenterMarksBestPricesAmongShownBookmakersAndHidesEmptyRows(): void
    {
        $pair = new WatchedMarket('ars-che-2026-10-18', 'btts');
        $this->watchList->syncPost(1, [$pair]);
        $this->fetcher($this->fixture())->run(self::NOW);
        $cached = $this->repository->find($pair);
        self::assertNotNull($cached);

        $bookmakers = (new BookmakerRepository(new InMemoryStore(), require __DIR__ . '/../../config/bookmakers.php'))
            ->enabled(['betfred', 'unibet', 'bet365']);
        $view = (new OddsTablePresenter($this->converter))->present($cached, $bookmakers, OddsFormat::American);

        // Betfred has no BTTS prices in the fixture, so its row is hidden.
        self::assertSame(['unibet', 'bet365'], array_column(array_column($view['rows'], 'bookmaker'), 'id'));
        self::assertSame('american', $view['format']);
        self::assertSame(['yes', 'no'], array_column($view['outcomes'], 'key'));

        foreach (['yes', 'no'] as $outcome) {
            $cells = array_column(array_column($view['rows'], 'prices'), $outcome);
            $max = max(array_column($cells, 'decimal'));
            foreach ($cells as $cell) {
                self::assertSame($cell['decimal'] === $max, $cell['best']);
                self::assertMatchesRegularExpression('/^[+-]\d+$/', $cell['value']);
            }
        }
    }

    public function testBookmakerRepositoryRespectsSettingsAndSelectionOrder(): void
    {
        $store = new InMemoryStore();
        $repo = new BookmakerRepository($store, require __DIR__ . '/../../config/bookmakers.php');
        self::assertCount(10, $repo->enabled());

        $store->set(BookmakerRepository::OPTION, [
            ['id' => 'bet365', 'name' => 'bet365', 'url' => 'https://example.com/a', 'enabled' => true],
            ['id' => 'betway', 'name' => 'Betway', 'url' => 'https://example.com/b', 'enabled' => false],
            ['id' => 'unibet', 'name' => 'Unibet', 'url' => 'https://example.com/c', 'enabled' => true],
        ]);

        $ids = static fn (array $list): array => array_map(static fn (Bookmaker $b): string => $b->id, $list);
        self::assertSame(['bet365', 'unibet'], $ids($repo->enabled()));
        self::assertSame(['unibet', 'bet365'], $ids($repo->enabled(['unibet', 'betway', 'bet365'])));
    }
}
