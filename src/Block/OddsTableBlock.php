<?php

declare(strict_types=1);

namespace BestOddsTable\Block;

use InvalidArgumentException;
use BestOddsTable\Domain\OddsFormat;
use BestOddsTable\Presentation\OddsTablePresenter;
use BestOddsTable\Repository\BookmakerRepository;
use BestOddsTable\Repository\MarketRepository;
use BestOddsTable\Repository\OddsRepository;
use BestOddsTable\Watch\WatchedMarket;

/**
 * Turns the odds table block's attributes into a view model.
 *
 * Kept out of render.php so the decisions (is the block configured, is the
 * market allowed, are odds cached) are plain PHP and unit tested. Reads the
 * cache only: rendering a page never calls an odds source.
 */
final class OddsTableBlock
{
    public function __construct(
        private readonly OddsRepository $odds,
        private readonly BookmakerRepository $bookmakers,
        private readonly MarketRepository $markets,
        private readonly OddsTablePresenter $presenter,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes Block attributes, as saved by any editor.
     *
     * @return array<string, mixed>|null Null when the block is not configured,
     *                                   its market is disabled in settings, or
     *                                   no odds are cached for it yet.
     */
    public function viewModel(array $attributes): ?array
    {
        try {
            $pair = new WatchedMarket(
                (string) ($attributes['eventId'] ?? ''),
                (string) ($attributes['marketKey'] ?? '')
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        if (!$this->markets->isEnabled($pair->marketKey)) {
            return null;
        }

        $cached = $this->odds->find($pair);
        if ($cached === null) {
            return null;
        }

        // Ids are only used as lookup keys against enabled bookmakers, so
        // unknown or stale ids simply match nothing.
        $bookmakerIds = array_values(array_filter((array) ($attributes['bookmakers'] ?? []), 'is_string'));

        return $this->presenter->present(
            $cached,
            $this->bookmakers->enabled($bookmakerIds),
            OddsFormat::fromInput((string) ($attributes['format'] ?? ''))
        );
    }
}
