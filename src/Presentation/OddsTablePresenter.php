<?php

declare(strict_types=1);

namespace BestOddsTable\Presentation;

use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\Bookmaker;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Repository\CachedMarket;

/**
 * Turns a cached market into the view model used by both the REST endpoint
 * and the block's server render, so the two can never disagree.
 */
final class OddsTablePresenter
{
    public function __construct(private readonly OddsConverter $converter)
    {
    }

    /**
     * @param list<Bookmaker> $bookmakers Bookmakers to show, in display order.
     *
     * @return array<string, mixed>
     */
    public function present(CachedMarket $cached, array $bookmakers, OddsFormat $format): array
    {
        $market = $cached->market;
        $outcomeKeys = array_keys($market->outcomes);
        $bookmakerIds = array_map(static fn (Bookmaker $b): string => $b->id, $bookmakers);

        // Best price per outcome among the bookmakers actually shown.
        $best = [];
        foreach ($outcomeKeys as $outcomeKey) {
            $best[$outcomeKey] = $market->bestPrice($outcomeKey, $bookmakerIds)?->odds->decimal();
        }

        $rows = [];
        foreach ($bookmakers as $bookmaker) {
            $prices = [];
            foreach ($outcomeKeys as $outcomeKey) {
                $price = $market->priceFor($bookmaker->id, $outcomeKey);
                $prices[$outcomeKey] = $price === null ? null : [
                    'value' => $this->converter->format($price->odds, $format),
                    'decimal' => round($price->odds->decimal(), 4),
                    'best' => $best[$outcomeKey] !== null
                        && abs($price->odds->decimal() - $best[$outcomeKey]) < 1e-9,
                ];
            }

            if (array_filter($prices) === []) {
                continue; // Bookmaker has no prices on this market; hide the row.
            }

            $rows[] = ['bookmaker' => $bookmaker->toArray(), 'prices' => $prices];
        }

        return [
            'event' => [
                'id' => $cached->event->id,
                'name' => $cached->event->name(),
                'competition' => $cached->event->competition,
                'starts_at' => $cached->event->startsAt->format(DATE_ATOM),
            ],
            'market' => ['key' => $market->key, 'label' => $market->label],
            'outcomes' => array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                $outcomeKeys,
                array_values($market->outcomes)
            ),
            'rows' => $rows,
            'format' => $format->value,
            'fetched_at' => gmdate(DATE_ATOM, $cached->fetchedAt),
            'stale' => $cached->stale,
        ];
    }
}
