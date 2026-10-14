<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\Event;
use BestOddsTable\Domain\Market;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Domain\Price;

/**
 * Builds domain Events from the normalised array shape every source produces.
 *
 * Sources only need to turn their raw data (HTML, JSON, CSV...) into this
 * array; validation and conversion live here, in one place.
 *
 * Expected shape:
 * [
 *   'id' => 'ars-che-2026-10-18', 'sport' => 'football', 'competition' => 'Premier League',
 *   'home' => 'Arsenal', 'away' => 'Chelsea', 'starts_at' => '2026-10-18T14:00:00Z',
 *   'markets' => [[
 *     'key' => 'match_result', 'label' => 'Match Result',
 *     'outcomes' => ['home' => 'Arsenal', 'draw' => 'Draw', 'away' => 'Chelsea'],
 *     'prices' => ['bet365' => ['home' => '6/4', 'draw' => '12/5', 'away' => '7/4']],
 *   ]],
 * ]
 */
final class EventFactory
{
    public function __construct(
        private readonly OddsConverter $converter,
        private readonly OddsFormat $priceFormat = OddsFormat::Fractional,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $marketKeys Empty means keep all markets.
     */
    public function fromArray(array $data, array $marketKeys = []): Event
    {
        foreach (['id', 'home', 'away', 'starts_at'] as $required) {
            if (empty($data[$required])) {
                throw new InvalidArgumentException(sprintf('Event is missing "%s".', $required));
            }
        }

        $markets = [];
        foreach ((array) ($data['markets'] ?? []) as $marketData) {
            $key = (string) ($marketData['key'] ?? '');
            if ($key === '' || ($marketKeys !== [] && !in_array($key, $marketKeys, true))) {
                continue;
            }
            $markets[$key] = $this->market($marketData);
        }

        return new Event(
            id: (string) $data['id'],
            sport: (string) ($data['sport'] ?? ''),
            competition: (string) ($data['competition'] ?? ''),
            home: (string) $data['home'],
            away: (string) $data['away'],
            startsAt: new DateTimeImmutable((string) $data['starts_at']),
            markets: $markets,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function market(array $data): Market
    {
        $outcomes = array_map('strval', (array) ($data['outcomes'] ?? []));
        $prices = [];

        foreach ((array) ($data['prices'] ?? []) as $bookmakerId => $byOutcome) {
            foreach ((array) $byOutcome as $outcomeKey => $raw) {
                if (!isset($outcomes[$outcomeKey])) {
                    continue; // Ignore outcomes the market does not declare.
                }
                try {
                    $odds = $this->converter->parse((string) $raw, $this->priceFormat);
                } catch (InvalidArgumentException) {
                    continue; // One malformed price must not drop the whole market.
                }
                $prices[] = new Price((string) $bookmakerId, (string) $outcomeKey, $odds);
            }
        }

        return new Market(
            key: (string) $data['key'],
            label: (string) ($data['label'] ?? $data['key']),
            outcomes: $outcomes,
            prices: $prices,
        );
    }
}
