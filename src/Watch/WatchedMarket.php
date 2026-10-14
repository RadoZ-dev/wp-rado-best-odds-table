<?php

declare(strict_types=1);

namespace BestOddsTable\Watch;

use InvalidArgumentException;
use BestOddsTable\Domain\Event;
use BestOddsTable\Domain\Market;

/**
 * One event/market pair that some content on the site displays.
 * This is the unit of fetching and caching.
 *
 * Both parts come from user-controlled input (block attributes, REST
 * parameters), so they are validated here, once, for every caller.
 */
final class WatchedMarket
{
    private const SEPARATOR = '|';

    public function __construct(
        public readonly string $eventId,
        public readonly string $marketKey,
    ) {
        if ($eventId === '' || $marketKey === '') {
            throw new InvalidArgumentException('Event id and market key are required.');
        }

        if (!preg_match(Event::ID_PATTERN, $eventId) || !preg_match(Market::KEY_PATTERN, $marketKey)) {
            throw new InvalidArgumentException('Invalid event id or market key.');
        }
    }

    public static function fromKey(string $key): self
    {
        [$eventId, $marketKey] = array_pad(explode(self::SEPARATOR, $key, 2), 2, '');

        return new self($eventId, $marketKey);
    }

    public function key(): string
    {
        return $this->eventId . self::SEPARATOR . $this->marketKey;
    }
}
