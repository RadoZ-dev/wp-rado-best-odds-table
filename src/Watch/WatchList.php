<?php

declare(strict_types=1);

namespace BestOddsTable\Watch;

use InvalidArgumentException;
use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Storage\KeyValueStore;

/**
 * The set of event/market pairs that published content actually shows.
 *
 * Fetching is driven by this list, not by "all sports": load grows with the
 * site's content, not with the number of events in the world.
 *
 * Two separate options are kept on purpose:
 *  - membership (which posts reference which pair), written on save_post;
 *  - fetch state (last fetch, start time), written by the scheduled fetcher.
 * Separate writers on separate rows avoid one overwriting the other's update.
 */
final class WatchList
{
    private const MEMBERSHIP = 'watch_membership';
    private const STATE = 'watch_state';

    public function __construct(private readonly KeyValueStore $store)
    {
    }

    /**
     * Replace the pairs referenced by one post.
     *
     * @param list<WatchedMarket> $pairs
     *
     * @return list<WatchedMarket> Pairs nobody watched before (fetch these right away).
     */
    public function syncPost(int $postId, array $pairs): array
    {
        /** @var array<string, list<int>> $membership */
        $membership = (array) $this->store->get(self::MEMBERSHIP, []);

        foreach ($membership as $key => $postIds) {
            $membership[$key] = array_values(array_diff($postIds, [$postId]));
        }

        $added = [];
        foreach ($pairs as $pair) {
            $key = $pair->key();
            if (empty($membership[$key])) {
                $added[] = $pair;
            }
            $membership[$key] = array_values(array_unique([...($membership[$key] ?? []), $postId]));
        }

        $this->store->set(self::MEMBERSHIP, array_filter($membership));

        return $added;
    }

    public function removePost(int $postId): void
    {
        $this->syncPost($postId, []);
    }

    /**
     * Forget when each pair was fetched, so every pair is due again.
     * Membership (which posts show which pair) is kept.
     */
    public function resetState(): void
    {
        $this->store->delete(self::STATE);
    }

    /**
     * Stored keys that are no longer valid (e.g. saved before the id rules
     * were tightened) are skipped: one bad row must not stop every cron run.
     * The next save of the post rewrites its membership without them.
     *
     * @return list<WatchedMarket>
     */
    public function all(): array
    {
        $pairs = [];
        foreach (array_keys((array) $this->store->get(self::MEMBERSHIP, [])) as $key) {
            try {
                $pairs[] = WatchedMarket::fromKey((string) $key);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $pairs;
    }

    /**
     * Pairs whose refresh interval has elapsed, never-fetched and most
     * overdue first, so a capped batch always serves the oldest data.
     *
     * @return list<WatchedMarket>
     */
    public function due(RefreshPolicy $policy, int $now): array
    {
        $state = (array) $this->store->get(self::STATE, []);
        $due = [];

        foreach ($this->all() as $pair) {
            $entry = $state[$pair->key()] ?? [];
            $lastFetched = $entry['last_fetched'] ?? null;
            if ($policy->isDue($lastFetched, $entry['starts_at'] ?? null, $now)) {
                $due[] = [$pair, $lastFetched ?? 0];
            }
        }

        usort($due, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return array_column($due, 0);
    }

    public function markFetched(WatchedMarket $pair, int $fetchedAt, ?int $startsAt): void
    {
        $state = (array) $this->store->get(self::STATE, []);
        $state[$pair->key()] = ['last_fetched' => $fetchedAt, 'starts_at' => $startsAt];

        // Only the fetcher writes state, so it also prunes pairs nobody watches any more.
        $membership = (array) $this->store->get(self::MEMBERSHIP, []);
        $this->store->set(self::STATE, array_intersect_key($state, $membership));
    }

    /**
     * @return array{last_fetched: int|null, starts_at: int|null}
     */
    public function state(WatchedMarket $pair): array
    {
        $state = (array) $this->store->get(self::STATE, []);

        return [
            'last_fetched' => $state[$pair->key()]['last_fetched'] ?? null,
            'starts_at' => $state[$pair->key()]['starts_at'] ?? null,
        ];
    }
}
