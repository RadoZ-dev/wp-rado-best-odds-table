<?php

declare(strict_types=1);

namespace BestOddsTable\Repository;

use InvalidArgumentException;
use BestOddsTable\Domain\Event;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Storage\CacheInterface;
use BestOddsTable\Storage\KeyValueStore;
use BestOddsTable\Watch\WatchedMarket;

/**
 * Read/write access to fetched odds. Page requests only ever read from here.
 *
 * Two layers:
 *  - hot cache (object cache / transients) with a TTL, for fast reads;
 *  - durable last-good snapshot (non-autoloaded option), written only on a
 *    successful fetch, so a failing source or a cache flush never leaves
 *    the page empty; readers get the last known odds, flagged as stale.
 *
 * Payloads are normalised arrays, never serialised objects, so a plugin
 * update cannot break unserialising old cache entries.
 */
final class OddsRepository
{
    private const INDEX_KEY = 'events_index';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly KeyValueStore $durable,
        private readonly EventFactory $decimalFactory,
    ) {
    }

    /**
     * Store every market of a freshly fetched event.
     */
    public function save(Event $event, int $fetchedAt, int $ttl): void
    {
        foreach (array_keys($event->markets()) as $marketKey) {
            $payload = [
                'event' => $event->toArray([$marketKey]),
                'fetched_at' => $fetchedAt,
            ];
            $key = $this->key(new WatchedMarket($event->id, $marketKey));

            $this->cache->set($key, $payload, $ttl);
            $this->durable->set($key, $payload);
        }
    }

    public function find(WatchedMarket $pair): ?CachedMarket
    {
        $key = $this->key($pair);
        $payload = $this->cache->get($key);
        $stale = false;

        if (!is_array($payload)) {
            $payload = $this->durable->get($key);
            $stale = true;
        }

        if (!is_array($payload) || !isset($payload['event'], $payload['fetched_at'])) {
            return null;
        }

        try {
            $event = $this->decimalFactory->fromArray((array) $payload['event']);
        } catch (InvalidArgumentException) {
            return null; // Corrupt entry: behave like a miss.
        }

        $market = $event->market($pair->marketKey);

        return $market === null ? null : new CachedMarket($event, $market, (int) $payload['fetched_at'], $stale);
    }

    /**
     * @param list<array<string, mixed>> $summaries
     */
    public function saveIndex(array $summaries, int $ttl): void
    {
        $this->cache->set(self::INDEX_KEY, $summaries, $ttl);
        $this->durable->set(self::INDEX_KEY, $summaries);
    }

    /**
     * @return list<array<string, mixed>>|null Null if the index was never built.
     */
    public function index(): ?array
    {
        $index = $this->cache->get(self::INDEX_KEY) ?? $this->durable->get(self::INDEX_KEY);

        return is_array($index) ? $index : null;
    }

    private function key(WatchedMarket $pair): string
    {
        // Hashed: event ids come from external sources and transient keys have a length limit.
        return 'odds_' . md5($pair->key());
    }
}
