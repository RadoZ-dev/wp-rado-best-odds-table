<?php

declare(strict_types=1);

namespace BestOddsTable\Fetch;

use BestOddsTable\Domain\Event;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Storage\CacheInterface;
use BestOddsTable\Storage\CacheLock;
use BestOddsTable\Storage\Lock;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;

/**
 * Scheduled job that refreshes watched odds. Never runs inside a page view.
 *
 * Protection against hammering the source:
 *  - only pairs that are due (RefreshPolicy) are fetched;
 *  - at most $batchSize requests per run; the rest wait for the next run;
 *  - a delay between requests;
 *  - an atomic lock so overlapping cron runs cannot double the load;
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

    private readonly Lock $lock;

    /**
     * @param callable(string, mixed...): void|null $dispatch
     * @param Lock|null                             $lock     Atomic in WordPress (WpOptionLock); defaults
     *                                                        to a simple cache lock for single-process use.
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
        ?Lock $lock = null,
    ) {
        $this->dispatch = $dispatch ?? static function (): void {
        };
        $this->lock = $lock ?? new CacheLock($cache);
    }

    public function run(int $now): FetchReport
    {
        if (!$this->lock->acquire(self::LOCK_KEY, self::LOCK_TTL)) {
            return new FetchReport(FetchReport::LOCKED);
        }

        try {
            return $this->runUnlocked($now);
        } finally {
            $this->lock->release(self::LOCK_KEY);
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
        // The source's own timestamp wins: a source serving a cached response
        // must not make old odds look fresh.
        $oddsAt = min($event->updatedAt?->getTimestamp() ?? $now, $now);
        $this->repository->save($event, $oddsAt, $this->policy->cacheTtl($startsAt, $now));
        $this->watchList->markFetched($pair, $now, $startsAt);
        ($this->dispatch)('best_odds_table_fetched', $pair, $event);

        return $event;
    }

    /**
     * Fetch one pair because a request asked for it (an editor previewing a block).
     *
     * Unlike run(), this is triggered by users, so it only fetches pairs the
     * source's index lists and never while backing off. Otherwise anyone who
     * can edit posts could send unlimited requests to the source.
     *
     * @return Event|null Null when the pair was not fetched or the source no longer lists it.
     *
     * @throws SourceException
     */
    public function fetchOnDemand(WatchedMarket $pair, int $now): ?Event
    {
        if ($this->isBackingOff($now) || $this->repository->isListed($pair) !== true) {
            return null;
        }

        return $this->fetchPair($pair, $now);
    }

    /**
     * Start over after the active source changed.
     *
     * Odds and the event index came from the previous source, which no longer
     * updates them; keeping them would show another source's prices, as stale
     * at best, forever. Its backoff is dropped too: a rate limit from one
     * provider says nothing about the next. Which posts show which pairs is
     * kept, and every pair becomes due for the next run.
     */
    public function resetForNewSource(): void
    {
        foreach ($this->watchList->all() as $pair) {
            $this->repository->forget($pair);
        }

        $this->repository->forgetIndex();
        $this->watchList->resetState();
        $this->cache->delete(self::BACKOFF_KEY);
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
        if ($this->isBackingOff($now)) {
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

    private function isBackingOff(int $now): bool
    {
        return (int) ($this->cache->get(self::BACKOFF_KEY) ?? 0) > $now;
    }
}
