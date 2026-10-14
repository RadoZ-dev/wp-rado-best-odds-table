<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use DateTimeImmutable;
use Exception;
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

        // Rejected here, at the boundary, so an odd id from a source skips one
        // event instead of failing later when it is stored or watched.
        if (!preg_match(Event::ID_PATTERN, (string) $data['id'])) {
            throw new InvalidArgumentException('Event has an invalid id.');
        }

        // DateTimeImmutable throws a plain Exception (DateMalformedStringException
        // on PHP 8.3+), which callers do not catch. Convert it, so one malformed
        // date skips that event instead of crashing the whole fetch run.
        try {
            $startsAt = new DateTimeImmutable((string) $data['starts_at']);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Event "%s" has an invalid start time.', (string) $data['id']),
                0,
                $e
            );
        }

        $markets = [];
        foreach ((array) ($data['markets'] ?? []) as $marketData) {
            $key = (string) ($marketData['key'] ?? '');
            if (!preg_match(Market::KEY_PATTERN, $key) || ($marketKeys !== [] && !in_array($key, $marketKeys, true))) {
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
            startsAt: $startsAt,
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
