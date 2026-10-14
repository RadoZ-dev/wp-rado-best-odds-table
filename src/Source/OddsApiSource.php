<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use BestOddsTable\Domain\Event;
use BestOddsTable\Http\HttpClient;
use BestOddsTable\Http\HttpResponse;
use BestOddsTable\Storage\CacheInterface;

/**
 * Live odds from The Odds API (the-odds-api.com), a licensed odds feed.
 *
 * One request per sport returns every upcoming match with all UK bookmakers'
 * match result prices, so cost does not grow with events or bookmakers. The
 * response is cached for $cacheTtl seconds, which caps quota use however often
 * the fetcher, the index refresh or editors ask: at the default 2 hours that
 * is 12 requests a day per sport, inside the free plan's 500 a month.
 *
 * Only match result is offered here. BTTS is a per-event "additional market"
 * on this API, costing one request per event; that is a plan decision, not
 * something to switch on by default.
 */
final class OddsApiSource implements OddsSourceInterface
{
    public const ID = 'the-odds-api';

    /**
     * The Odds API bookmaker keys (UK region) => this plugin's bookmaker ids.
     * Bookmakers not listed are ignored. bet365 is not offered by the API.
     */
    public const BOOKMAKERS = [
        'williamhill' => 'william-hill',
        'paddypower' => 'paddy-power',
        'betfair_sb_uk' => 'betfair', // The sportsbook; betfair_ex_uk is the exchange.
        'skybet' => 'sky-bet',
        'ladbrokes_uk' => 'ladbrokes',
        'coral' => 'coral',
        'betfred_uk' => 'betfred',
        'unibet_uk' => 'unibet',
        'betway' => 'betway',
    ];

    private const BASE_URL = 'https://api.the-odds-api.com/v4/sports/';
    private const MARKET = 'match_result';
    private const OUT_OF_CREDITS_RETRY = 6 * 3600;

    /**
     * @param EventFactory          $factory      Must parse decimal prices; the API is asked for decimal odds.
     * @param array<string, string> $sports       The Odds API sport key => competition name, e.g. soccer_epl => Premier League.
     * @param array<string, string> $bookmakerMap The Odds API bookmaker key => plugin bookmaker id.
     * @param string                $label        Display name; the plugin passes a translated one.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly CacheInterface $cache,
        private readonly EventFactory $factory,
        private readonly string $apiKey,
        private readonly array $sports = ['soccer_epl' => 'Premier League'],
        private readonly array $bookmakerMap = self::BOOKMAKERS,
        private readonly int $cacheTtl = 2 * 3600,
        private readonly string $label = 'The Odds API (live, match result)',
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function fetchEvents(array $marketKeys = []): array
    {
        if (!$this->offers($marketKeys)) {
            return [];
        }

        $events = [];
        foreach (array_keys($this->sports) as $sportKey) {
            foreach ($this->sportEvents($sportKey) as $row) {
                try {
                    $events[] = $this->factory->fromArray($row, $marketKeys);
                } catch (InvalidArgumentException) {
                    continue; // Skip one unusable event, keep the rest.
                }
            }
        }

        return $events;
    }

    public function fetchEvent(string $eventId, array $marketKeys = []): ?Event
    {
        if (!$this->offers($marketKeys)) {
            return null;
        }

        foreach (array_keys($this->sports) as $sportKey) {
            foreach ($this->sportEvents($sportKey) as $row) {
                if ($row['id'] === $eventId) {
                    try {
                        return $this->factory->fromArray($row, $marketKeys);
                    } catch (InvalidArgumentException) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $marketKeys
     */
    private function offers(array $marketKeys): bool
    {
        return $marketKeys === [] || in_array(self::MARKET, $marketKeys, true);
    }

    /**
     * Normalised events of one sport, from cache or one API request.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SourceException
     */
    private function sportEvents(string $sportKey): array
    {
        $cacheKey = 'odds_api_' . md5($sportKey);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->normalise($this->request($sportKey), $this->sports[$sportKey], time());
        $this->cache->set($cacheKey, $rows, $this->cacheTtl);

        return $rows;
    }

    /**
     * @return list<mixed> Decoded response body.
     *
     * @throws SourceException
     */
    private function request(string $sportKey): array
    {
        $url = self::BASE_URL . rawurlencode($sportKey) . '/odds/?' . http_build_query([
            'apiKey' => $this->apiKey,
            'regions' => 'uk',
            'markets' => 'h2h',
            'oddsFormat' => 'decimal',
            'dateFormat' => 'iso',
        ]);

        // Messages never include $url: it carries the API key, and these
        // messages can reach logs and admin screens.
        try {
            $response = $this->http->get($url);
        } catch (RuntimeException $e) {
            throw new SourceException('The Odds API could not be reached: ' . $e->getMessage(), null, $e);
        }

        $this->assertOk($response);

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SourceException('The Odds API returned invalid JSON.', null, $e);
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new SourceException('The Odds API returned an unexpected response.');
        }

        return $data;
    }

    /**
     * @throws SourceException
     */
    private function assertOk(HttpResponse $response): void
    {
        if ($response->status === 200) {
            return;
        }

        if ($response->status === 429) {
            throw SourceException::rateLimited(self::ID, max(1, (int) ($response->header('Retry-After') ?? 60)));
        }

        // An exhausted monthly quota answers 401 too; back off for hours rather
        // than failing every five minutes until the quota resets.
        if ($response->status === 401 && str_contains($response->body, 'OUT_OF_USAGE_CREDITS')) {
            throw SourceException::rateLimited(self::ID, self::OUT_OF_CREDITS_RETRY);
        }

        if ($response->status === 401) {
            throw new SourceException('The Odds API rejected the API key.');
        }

        throw new SourceException(sprintf('The Odds API returned HTTP %d.', $response->status));
    }

    /**
     * API events => the normalised shape EventFactory reads.
     *
     * Outcomes are matched by team name, because the API lists them in no
     * fixed order. Prices stay strings for the converter.
     *
     * @param list<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function normalise(array $data, string $competition, int $fetchedAt): array
    {
        $rows = [];

        foreach ($data as $event) {
            if (!is_array($event)) {
                continue;
            }

            $home = (string) ($event['home_team'] ?? '');
            $away = (string) ($event['away_team'] ?? '');
            $prices = [];

            foreach ((array) ($event['bookmakers'] ?? []) as $bookmaker) {
                $bookmakerId = $this->bookmakerMap[(string) ($bookmaker['key'] ?? '')] ?? null;
                if ($bookmakerId === null) {
                    continue; // A bookmaker this site does not list.
                }

                foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                    if (($market['key'] ?? null) !== 'h2h') {
                        continue;
                    }

                    foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                        $outcomeKey = match ((string) ($outcome['name'] ?? '')) {
                            $home => 'home',
                            $away => 'away',
                            'Draw' => 'draw',
                            default => null,
                        };

                        if ($outcomeKey !== null && isset($outcome['price'])) {
                            $prices[$bookmakerId][$outcomeKey] = (string) $outcome['price'];
                        }
                    }
                }
            }

            $rows[] = [
                'id' => (string) ($event['id'] ?? ''),
                'sport' => 'football',
                'competition' => $competition,
                'home' => $home,
                'away' => $away,
                'starts_at' => (string) ($event['commence_time'] ?? ''),
                'updated_at' => gmdate(DATE_ATOM, $fetchedAt),
                'markets' => [[
                    'key' => self::MARKET,
                    'label' => 'Match Result',
                    'outcomes' => ['home' => $home, 'draw' => 'Draw', 'away' => $away],
                    'prices' => $prices,
                ]],
            ];
        }

        return $rows;
    }
}
