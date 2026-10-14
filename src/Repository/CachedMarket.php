<?php

declare(strict_types=1);

namespace BestOddsTable\Repository;

use BestOddsTable\Domain\Event;
use BestOddsTable\Domain\Market;

/**
 * A market read from storage, with when it was fetched and whether it came
 * from the last-good snapshot because the hot cache had expired.
 */
final class CachedMarket
{
    public function __construct(
        public readonly Event $event,
        public readonly Market $market,
        public readonly int $fetchedAt,
        public readonly bool $stale,
    ) {
    }
}
