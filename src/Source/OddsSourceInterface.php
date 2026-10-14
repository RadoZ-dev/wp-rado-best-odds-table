<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use BestOddsTable\Domain\Event;

/**
 * Contract for anything that can supply odds: a scraper, a licensed API,
 * or a local fixture. The rest of the plugin only depends on this interface,
 * so adding a provider never touches caching, REST or the block.
 */
interface OddsSourceInterface
{
    /**
     * Stable machine id, e.g. "oddschecker".
     */
    public function id(): string;

    /**
     * Human readable name for the admin screen.
     */
    public function label(): string;

    /**
     * Fetch current events and odds.
     *
     * Implementations must not be called during a page request; the
     * scheduled fetcher calls them and caches the result.
     *
     * @param list<string> $marketKeys Markets to include; empty means all.
     *
     * @return list<Event>
     *
     * @throws SourceException When the source is unreachable or returns unusable data.
     */
    public function fetchEvents(array $marketKeys = []): array;

    /**
     * Fetch one event with the given markets.
     *
     * This is the hot path: the fetcher calls it once per watched
     * event/market pair. On a comparison site one event page lists every
     * bookmaker, so this is one request regardless of bookmaker count.
     *
     * @param list<string> $marketKeys
     *
     * @return Event|null Null when the source no longer lists the event.
     *
     * @throws SourceException
     */
    public function fetchEvent(string $eventId, array $marketKeys = []): ?Event;
}
