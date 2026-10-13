<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

use InvalidArgumentException;

/**
 * Immutable odds value.
 *
 * Odds are always stored as a decimal number, because decimal is the only
 * format that is lossless to compare and sort. Fractional and American are
 * presentation formats; conversion happens in OddsConverter at the edges.
 */
final class Odds
{
    private function __construct(private readonly float $decimal)
    {
    }

    public static function fromDecimal(float $decimal): self
    {
        if (!is_finite($decimal) || $decimal <= 1.0) {
            throw new InvalidArgumentException(
                sprintf('Decimal odds must be greater than 1.0, got %s.', var_export($decimal, true))
            );
        }

        return new self($decimal);
    }

    public function decimal(): float
    {
        return $this->decimal;
    }

    /**
     * Implied probability, 0..1. Useful for spotting outliers in scraped data.
     */
    public function impliedProbability(): float
    {
        return 1 / $this->decimal;
    }

    public function isBetterThan(self $other): bool
    {
        return $this->decimal > $other->decimal;
    }
}
