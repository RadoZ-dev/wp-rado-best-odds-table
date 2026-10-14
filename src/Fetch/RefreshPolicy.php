<?php

declare(strict_types=1);

namespace BestOddsTable\Fetch;

/**
 * How often a pair is refreshed, based on how close its event is.
 *
 * Odds move most just before kick-off, so near events refresh often and
 * distant ones rarely. Finished events are never fetched again.
 */
final class RefreshPolicy
{
    /**
     * @param int $soonWindow    Seconds before start that count as "soon".
     * @param int $soonInterval  Refresh interval for soon or in-play events.
     * @param int $dayWindow     Seconds before start that count as "today".
     * @param int $dayInterval   Refresh interval for today's events.
     * @param int $farInterval   Refresh interval for everything further out.
     * @param int $finishedAfter Seconds after start when an event counts as finished.
     */
    public function __construct(
        private readonly int $soonWindow = 3 * 3600,
        private readonly int $soonInterval = 5 * 60,
        private readonly int $dayWindow = 24 * 3600,
        private readonly int $dayInterval = 15 * 60,
        private readonly int $farInterval = 3600,
        private readonly int $finishedAfter = 3 * 3600,
    ) {
    }

    /**
     * @return int|null Seconds between fetches, or null if the event is finished.
     */
    public function intervalFor(?int $startsAt, int $now): ?int
    {
        if ($startsAt === null) {
            return $this->soonInterval; // Unknown start: treat as urgent but bounded.
        }

        $untilStart = $startsAt - $now;

        return match (true) {
            $untilStart < -$this->finishedAfter => null,
            $untilStart <= $this->soonWindow => $this->soonInterval,
            $untilStart <= $this->dayWindow => $this->dayInterval,
            default => $this->farInterval,
        };
    }

    public function isDue(?int $lastFetched, ?int $startsAt, int $now): bool
    {
        $interval = $this->intervalFor($startsAt, $now);

        if ($interval === null) {
            return false;
        }

        return $lastFetched === null || $now - $lastFetched >= $interval;
    }

    /**
     * Hot-cache lifetime: two refresh cycles, so one failed fetch does not
     * immediately push readers onto the stale snapshot.
     */
    public function cacheTtl(?int $startsAt, int $now): int
    {
        return 2 * ($this->intervalFor($startsAt, $now) ?? $this->farInterval);
    }
}
