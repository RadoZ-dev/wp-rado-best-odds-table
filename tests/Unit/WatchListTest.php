<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Fetch\RefreshPolicy;
use BestOddsTable\Tests\Support\InMemoryStore;
use BestOddsTable\Watch\BlockScanner;
use BestOddsTable\Watch\WatchedMarket;
use BestOddsTable\Watch\WatchList;
use PHPUnit\Framework\TestCase;

final class WatchListTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private WatchList $list;
    private WatchedMarket $a;
    private WatchedMarket $b;

    protected function setUp(): void
    {
        $this->list = new WatchList(new InMemoryStore());
        $this->a = new WatchedMarket('ars-che', 'match_result');
        $this->b = new WatchedMarket('liv-mci', 'btts');
    }

    /**
     * @param list<WatchedMarket> $pairs
     *
     * @return list<string>
     */
    private static function keys(array $pairs): array
    {
        return array_map(static fn (WatchedMarket $p): string => $p->key(), $pairs);
    }

    public function testSyncReturnsOnlyNewlyWatchedPairs(): void
    {
        self::assertSame(['ars-che|match_result'], self::keys($this->list->syncPost(1, [$this->a])));
        self::assertSame([], $this->list->syncPost(2, [$this->a]), 'already watched by post 1');
        self::assertSame(['liv-mci|btts'], self::keys($this->list->syncPost(2, [$this->a, $this->b])));
    }

    public function testPairStaysWhileAnyPostUsesIt(): void
    {
        $this->list->syncPost(1, [$this->a]);
        $this->list->syncPost(2, [$this->a, $this->b]);

        $this->list->removePost(1);
        self::assertSame(['ars-che|match_result', 'liv-mci|btts'], self::keys($this->list->all()));

        $this->list->syncPost(2, [$this->b]);
        self::assertSame(['liv-mci|btts'], self::keys($this->list->all()));
    }

    public function testDueOrdersNeverFetchedFirstThenOldest(): void
    {
        $c = new WatchedMarket('rma-bar', 'match_result');
        $this->list->syncPost(1, [$this->a, $this->b, $c]);
        $soon = self::NOW + 1800;

        $this->list->markFetched($this->a, self::NOW - 400, $soon);
        $this->list->markFetched($this->b, self::NOW - 600, $soon);

        self::assertSame(
            ['rma-bar|match_result', 'liv-mci|btts', 'ars-che|match_result'],
            self::keys($this->list->due(new RefreshPolicy(), self::NOW))
        );

        $this->list->markFetched($this->a, self::NOW, $soon);
        self::assertNotContains('ars-che|match_result', self::keys($this->list->due(new RefreshPolicy(), self::NOW)));
    }

    public function testFetchStateOfUnwatchedPairsIsPruned(): void
    {
        $this->list->syncPost(1, [$this->a, $this->b]);
        $this->list->markFetched($this->a, self::NOW, null);
        $this->list->syncPost(1, [$this->b]);
        $this->list->markFetched($this->b, self::NOW, null);

        self::assertNull($this->list->state($this->a)['last_fetched']);
        self::assertSame(self::NOW, $this->list->state($this->b)['last_fetched']);
    }

    public function testScannerFindsNestedConfiguredBlocksOnly(): void
    {
        $blocks = [
            ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []],
            ['blockName' => BlockScanner::BLOCK_NAME, 'attrs' => ['eventId' => 'ars-che', 'marketKey' => 'match_result'], 'innerBlocks' => []],
            ['blockName' => 'core/columns', 'attrs' => [], 'innerBlocks' => [
                ['blockName' => BlockScanner::BLOCK_NAME, 'attrs' => ['eventId' => 'liv-mci', 'marketKey' => 'btts'], 'innerBlocks' => []],
                ['blockName' => BlockScanner::BLOCK_NAME, 'attrs' => ['eventId' => 'ars-che', 'marketKey' => 'match_result'], 'innerBlocks' => []],
                ['blockName' => BlockScanner::BLOCK_NAME, 'attrs' => [], 'innerBlocks' => []],
            ]],
        ];

        self::assertSame(
            ['ars-che|match_result', 'liv-mci|btts'],
            self::keys((new BlockScanner())->pairs($blocks))
        );
    }
}
