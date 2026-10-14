<?php

declare(strict_types=1);

namespace BestOddsTable\Watch;

use InvalidArgumentException;

/**
 * Finds odds blocks in parsed post content (the output of parse_blocks()).
 *
 * Works on plain arrays, so it is testable without WordPress.
 */
final class BlockScanner
{
    public const BLOCK_NAME = 'best-odds-table/odds-table';

    /**
     * @param array<int, array<string, mixed>> $blocks
     *
     * @return list<WatchedMarket> Unique pairs, in content order.
     */
    public function pairs(array $blocks): array
    {
        $found = [];
        $this->collect($blocks, $found);

        return array_values($found);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, WatchedMarket>     $found
     */
    private function collect(array $blocks, array &$found): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === self::BLOCK_NAME) {
                $attrs = (array) ($block['attrs'] ?? []);
                try {
                    $pair = new WatchedMarket(
                        (string) ($attrs['eventId'] ?? ''),
                        (string) ($attrs['marketKey'] ?? '')
                    );
                    $found[$pair->key()] ??= $pair;
                } catch (InvalidArgumentException) {
                    // Block not configured yet; nothing to watch.
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->collect((array) $block['innerBlocks'], $found);
            }
        }
    }
}
