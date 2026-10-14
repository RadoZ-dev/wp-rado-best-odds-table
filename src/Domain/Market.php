<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

/**
 * A betting market (e.g. Match Result) with its outcomes and every
 * bookmaker's price for each outcome.
 */
final class Market
{
    /**
     * Allowed market keys, e.g. "match_result". Same reasoning as Event::ID_PATTERN.
     */
    public const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /**
     * @param array<string, string> $outcomes Outcome key => label, in display order.
     * @param list<Price>           $prices
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $outcomes,
        private readonly array $prices,
    ) {
    }

    /**
     * @return list<Price>
     */
    public function prices(): array
    {
        return $this->prices;
    }

    public function priceFor(string $bookmakerId, string $outcomeKey): ?Price
    {
        foreach ($this->prices as $price) {
            if ($price->bookmakerId === $bookmakerId && $price->outcomeKey === $outcomeKey) {
                return $price;
            }
        }

        return null;
    }

    /**
     * Highest odds for an outcome, optionally limited to some bookmakers
     * (the block lets editors pick which bookmakers to compare).
     *
     * @param list<string>|null $bookmakerIds
     */
    public function bestPrice(string $outcomeKey, ?array $bookmakerIds = null): ?Price
    {
        $best = null;

        foreach ($this->prices as $price) {
            if ($price->outcomeKey !== $outcomeKey) {
                continue;
            }
            if ($bookmakerIds !== null && !in_array($price->bookmakerId, $bookmakerIds, true)) {
                continue;
            }
            if ($best === null || $price->odds->isBetterThan($best->odds)) {
                $best = $price;
            }
        }

        return $best;
    }

    /**
     * Normalised array in the shape EventFactory reads, with decimal prices.
     * Used for caching, so domain objects are never serialised directly.
     *
     * @return array{key: string, label: string, outcomes: array<string, string>, prices: array<string, array<string, string>>}
     */
    public function toArray(): array
    {
        $prices = [];
        foreach ($this->prices as $price) {
            // Six places keep fractions like 10/11 recoverable after a round trip.
            $prices[$price->bookmakerId][$price->outcomeKey] = (string) round($price->odds->decimal(), 6);
        }

        return [
            'key' => $this->key,
            'label' => $this->label,
            'outcomes' => $this->outcomes,
            'prices' => $prices,
        ];
    }

    /**
     * @return list<string>
     */
    public function bookmakerIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (Price $price): string => $price->bookmakerId,
            $this->prices
        )));
    }
}
