<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Integration\LiteSpeedCache;
use PHPUnit\Framework\TestCase;

final class LiteSpeedCacheTest extends TestCase
{
    public function testTagIsStableShortAndSafeForAnyEventId(): void
    {
        $tag = LiteSpeedCache::tag('764e6513cb3f348881503df70ccb90e0', 'match_result');

        // Render and purge compute it separately, so it must be identical both times.
        self::assertSame($tag, LiteSpeedCache::tag('764e6513cb3f348881503df70ccb90e0', 'match_result'));
        self::assertMatchesRegularExpression('/^bot_[a-f0-9]{12}$/', $tag);
        self::assertMatchesRegularExpression('/^bot_[a-f0-9]{12}$/', LiteSpeedCache::tag('weird id, "quotes"', 'x'));
    }

    public function testEachPairHasItsOwnTag(): void
    {
        self::assertNotSame(LiteSpeedCache::tag('a', 'match_result'), LiteSpeedCache::tag('a', 'btts'));
        self::assertNotSame(LiteSpeedCache::tag('a', 'match_result'), LiteSpeedCache::tag('b', 'match_result'));
        self::assertNotSame(LiteSpeedCache::TAG_ALL, LiteSpeedCache::tag('a', 'match_result'));
    }
}
