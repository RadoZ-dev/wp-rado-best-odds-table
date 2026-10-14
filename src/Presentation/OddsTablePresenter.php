<?php

declare(strict_types=1);

namespace BestOddsTable\Presentation;

use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Domain\Bookmaker;
use BestOddsTable\Domain\Odds;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Repository\CachedMarket;

/**
 * Turns a cached market into the view model used by both the REST endpoint
 * and the block's server render, so the two can never disagree.
 *
 * Sources name markets and outcomes in English. Display names passed here
 * (translated by the plugin) replace them for the keys they cover; anything
 * else, such as team names, is shown as the source gave it.
 */
final class OddsTablePresenter
{
    /**
     * @param array<string, string> $marketLabels  Market key => display name.
     * @param array<string, string> $outcomeLabels Outcome key => display name, e.g. draw => "Draw".
     */
    public function __construct(
        private readonly OddsConverter $converter,
        private readonly array $marketLabels = [],
        private readonly array $outcomeLabels = [],
    ) {
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
                    // Every format, so the visitor can switch without a request
                    // and a cached page still serves any format.
                    'formats' => $this->allFormats($price->odds),
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
            'market' => ['key' => $market->key, 'label' => $this->marketLabels[$market->key] ?? $market->label],
            'outcomes' => array_map(
                fn (string $key, string $label): array => ['key' => $key, 'label' => $this->outcomeLabels[$key] ?? $label],
                $outcomeKeys,
                array_values($market->outcomes)
            ),
            'rows' => $rows,
            'format' => $format->value,
            'fetched_at' => gmdate(DATE_ATOM, $cached->fetchedAt),
            'stale' => $cached->stale,
        ];
    }

    /**
     * @return array<string, string> Format value => formatted odds.
     */
    private function allFormats(Odds $odds): array
    {
        $formats = [];
        foreach (OddsFormat::cases() as $format) {
            $formats[$format->value] = $this->converter->format($odds, $format);
        }

        return $formats;
    }
}
