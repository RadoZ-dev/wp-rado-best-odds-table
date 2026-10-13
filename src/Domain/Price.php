<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

/**
 * One bookmaker's odds for one outcome of a market.
 */
final class Price
{
    public function __construct(
        public readonly string $bookmakerId,
        public readonly string $outcomeKey,
        public readonly Odds $odds,
    ) {
    }
}
