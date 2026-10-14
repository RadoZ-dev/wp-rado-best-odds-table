<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Fetch\OddsFetcher;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Http\HttpClient;
use BestOddsTable\Http\HttpResponse;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\OddsApiSource;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Tests\Support\InMemoryStore;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;
use PHPUnit\Framework\TestCase;

/**
 * Uses a recorded API response (tests/Fixtures/odds-api-epl.json); never calls the network.
 */
final class OddsApiSourceTest extends TestCase
{
    private const KEY = 'test-secret-key';
    private const EVERTON_CHELSEA = '764e6513cb3f348881503df70ccb90e0';

    /** @var list<string> URLs the fake HTTP client was asked for. */
    private array $requested = [];

    private InMemoryStore $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryStore();
        $this->requested = [];
    }

    private function source(HttpResponse $response): OddsApiSource
    {
        $record = function (string $url): void {
            $this->requested[] = $url;
        };

        $http = new class ($response, $record) implements HttpClient {
            public function __construct(
                private readonly HttpResponse $response,
                private readonly \Closure $record,
            ) {
            }

            public function get(string $url): HttpResponse
            {
                ($this->record)($url);

                return $this->response;
            }
        };

        return new OddsApiSource(
            $http,
            $this->cache,
            new EventFactory(new OddsConverter(), OddsFormat::Decimal),
            self::KEY,
        );
    }

    private function recorded(): HttpResponse
    {
        return new HttpResponse(200, (string) file_get_contents(__DIR__ . '/../Fixtures/odds-api-epl.json'));
    }

    public function testMapsEventsBookmakersAndOutcomesByTeamName(): void
    {
        $events = $this->source($this->recorded())->fetchEvents();

        self::assertCount(2, $events);
        $event = $events[0];
        self::assertSame(self::EVERTON_CHELSEA, $event->id);
        self::assertSame('Everton v Chelsea', $event->name());
        self::assertSame('Premier League', $event->competition);
        self::assertNotNull($event->updatedAt);

        $market = $event->market('match_result');
        self::assertNotNull($market);
        // Nine mapped bookmakers; unmapped ones (LeoVegas, 888sport, the Betfair exchange...) are ignored.
        self::assertCount(9, $market->bookmakerIds());
        self::assertNotContains('bet365', $market->bookmakerIds());

        // The API lists William Hill's Chelsea (away) price first; matching is by name.
        self::assertSame(3.1, $market->priceFor('william-hill', 'home')?->odds->decimal());
        self::assertSame(2.1, $market->priceFor('william-hill', 'away')?->odds->decimal());
        self::assertSame(3.5, $market->priceFor('william-hill', 'draw')?->odds->decimal());
    }

    public function testOneRequestServesTheIndexAndEveryPairUntilTheCacheExpires(): void
    {
        $source = $this->source($this->recorded());

        $source->fetchEvents();
        self::assertNotNull($source->fetchEvent(self::EVERTON_CHELSEA, ['match_result']));
        self::assertNull($source->fetchEvent('unknown', ['match_result']));
        self::assertCount(1, $this->requested);

        $this->cache->flush();
        $source->fetchEvents();
        self::assertCount(2, $this->requested);
    }

    public function testMarketsTheSourceDoesNotOfferCostNoRequest(): void
    {
        $source = $this->source($this->recorded());

        self::assertSame([], $source->fetchEvents(['btts']));
        self::assertNull($source->fetchEvent(self::EVERTON_CHELSEA, ['btts']));
        self::assertSame([], $this->requested);
    }

    public function testRequestsDecimalUkMatchResultOdds(): void
    {
        $this->source($this->recorded())->fetchEvents();

        $query = [];
        parse_str((string) parse_url($this->requested[0], PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://api.the-odds-api.com/v4/sports/soccer_epl/odds/', $this->requested[0]);
        self::assertSame(['apiKey' => self::KEY, 'regions' => 'uk', 'markets' => 'h2h', 'oddsFormat' => 'decimal', 'dateFormat' => 'iso'], $query);
    }

    public function testRateLimitBacksOffForRetryAfter(): void
    {
        try {
            $this->source(new HttpResponse(429, '', ['Retry-After' => '120']))->fetchEvents();
            self::fail('Expected a rate-limit exception.');
        } catch (SourceException $e) {
            self::assertSame(120, $e->retryAfter);
        }
    }

    public function testExhaustedQuotaBacksOffForHours(): void
    {
        try {
            $this->source(new HttpResponse(401, '{"error_code":"OUT_OF_USAGE_CREDITS"}'))->fetchEvents();
            self::fail('Expected a rate-limit exception.');
        } catch (SourceException $e) {
            self::assertSame(6 * 3600, $e->retryAfter);
        }
    }

    public function testErrorsNeverExposeTheApiKey(): void
    {
        $responses = [
            new HttpResponse(401, '{"error_code":"INVALID_KEY"}'),
            new HttpResponse(500, 'oops'),
            new HttpResponse(200, 'not json'),
        ];

        foreach ($responses as $response) {
            try {
                $this->source($response)->fetchEvents();
                self::fail('Expected a source exception.');
            } catch (SourceException $e) {
                self::assertNull($e->retryAfter);
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function testFetcherStoresTheSourceTimestampNotTheFetchTime(): void
    {
        $odds = new OddsRepository(
            new InMemoryStore(),
            new InMemoryStore(),
            new EventFactory(new OddsConverter(), OddsFormat::Decimal)
        );
        $fetcher = new OddsFetcher(
            $this->source($this->recorded()),
            new WatchList(new InMemoryStore()),
            $odds,
            new RefreshPolicy(),
            new InMemoryStore(),
        );
        $pair = new WatchedMarket(self::EVERTON_CHELSEA, 'match_result');

        // Served from the source's cache an hour after the API request.
        $sourceTime = time();
        $fetcher->fetchPair($pair, $sourceTime + 3600);

        self::assertEqualsWithDelta($sourceTime, $odds->find($pair)?->fetchedAt, 5);
    }
}
