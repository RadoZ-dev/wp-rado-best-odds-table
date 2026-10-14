<?php

declare(strict_types=1);

namespace BestOddsTable\Fetch;

use BestOddsTable\Domain\Event;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Storage\CacheInterface;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;

/**
 * Scheduled job that refreshes watched odds. Never runs inside a page view.
 *
 * Protection against hammering the source:
 *  - only pairs that are due (RefreshPolicy) are fetched;
 *  - at most $batchSize requests per run; the rest wait for the next run;
 *  - a delay between requests;
 *  - a lock so overlapping cron runs cannot double the load;
 *  - on a rate-limit response the whole fetcher backs off for retry-after.
 *
 * Events are announced through $dispatch (do_action in WordPress), so other
 * code can react without the fetcher knowing about it, e.g. purge a page
 * cache after new odds arrive.
 */
final class OddsFetcher
{
    private const LOCK_KEY = 'fetch_lock';
    private const LOCK_TTL = 120;
    private const BACKOFF_KEY = 'backoff_until';
    private const INDEX_TTL = 2 * 3600;

    /** @var callable(string, mixed...): void */
    private $dispatch;

    /**
     * @param callable(string, mixed...): void|null $dispatch
     */
    public function __construct(
        private readonly OddsSourceInterface $source,
        private readonly WatchList $watchList,
        private readonly OddsRepository $repository,
        private readonly RefreshPolicy $policy,
        private readonly CacheInterface $cache,
        private readonly int $batchSize = 20,
        private readonly int $delayMicroseconds = 250_000,
        ?callable $dispatch = null,
    ) {
        $this->dispatch = $dispatch ?? static function (): void {
        };
    }

    public function run(int $now): FetchReport
    {
        if ($this->cache->get(self::LOCK_KEY) !== null) {
            return new FetchReport(FetchReport::LOCKED);
        }
        $this->cache->set(self::LOCK_KEY, $now, self::LOCK_TTL);

        try {
            return $this->runUnlocked($now);
        } finally {
            $this->cache->delete(self::LOCK_KEY);
        }
    }

    /**
     * Fetch one pair immediately and store it.
     *
     * @throws SourceException
     */
    public function fetchPair(WatchedMarket $pair, int $now): ?Event
    {
        $event = $this->source->fetchEvent($pair->eventId, [$pair->marketKey]);

        if ($event === null || $event->market($pair->marketKey) === null) {
            // Source no longer lists it. Record the attempt so it is not retried every run.
            $this->watchList->markFetched($pair, $now, $this->watchList->state($pair)['starts_at']);
            ($this->dispatch)('best_odds_table_market_missing', $pair);

            return null;
        }

        $startsAt = $event->startsAt->getTimestamp();
        $this->repository->save($event, $now, $this->policy->cacheTtl($startsAt, $now));
        $this->watchList->markFetched($pair, $now, $startsAt);
        ($this->dispatch)('best_odds_table_fetched', $pair, $event);

        return $event;
    }

    /**
     * Rebuild the list of available events for the block editor's picker.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SourceException
     */
    public function refreshIndex(): array
    {
        $summaries = array_map(
            static fn (Event $event): array => $event->summary(),
            $this->source->fetchEvents()
        );
        $this->repository->saveIndex($summaries, self::INDEX_TTL);

        return $summaries;
    }

    private function runUnlocked(int $now): FetchReport
    {
        $backoffUntil = (int) ($this->cache->get(self::BACKOFF_KEY) ?? 0);
        if ($backoffUntil > $now) {
            return new FetchReport(FetchReport::BACKING_OFF);
        }

        $due = $this->watchList->due($this->policy, $now);
        $batch = array_slice($due, 0, $this->batchSize);
        $fetched = 0;
        $failed = 0;

        foreach ($batch as $i => $pair) {
            if ($i > 0 && $this->delayMicroseconds > 0) {
                usleep($this->delayMicroseconds);
            }

            try {
                $this->fetchPair($pair, $now) !== null ? $fetched++ : $failed++;
            } catch (SourceException $e) {
                ($this->dispatch)('best_odds_table_fetch_failed', $pair, $e);

                if ($e->retryAfter !== null) {
                    $this->cache->set(self::BACKOFF_KEY, $now + $e->retryAfter, $e->retryAfter);

                    return new FetchReport(FetchReport::RATE_LIMITED, $fetched, $failed + 1, count($due) - $i - 1);
                }

                $failed++;
            }
        }

        return new FetchReport(FetchReport::OK, $fetched, $failed, count($due) - count($batch));
    }
}
