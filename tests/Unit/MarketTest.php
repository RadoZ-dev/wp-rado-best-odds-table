<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Domain\Market;
use BestOddsTable\Domain\Odds;
use BestOddsTable\Domain\Price;
use PHPUnit\Framework\TestCase;

final class MarketTest extends TestCase
{
    private Market $market;

    protected function setUp(): void
    {
        $this->market = new Market(
            key: 'match_result',
            label: 'Match Result',
            outcomes: ['home' => 'Arsenal', 'draw' => 'Draw', 'away' => 'Chelsea'],
            prices: [
                new Price('bet365', 'home', Odds::fromDecimal(2.0)),
                new Price('unibet', 'home', Odds::fromDecimal(2.2)),
                new Price('betway', 'home', Odds::fromDecimal(2.1)),
                new Price('bet365', 'away', Odds::fromDecimal(3.5)),
            ],
        );
    }

    public function testBestPriceAcrossAllBookmakers(): void
    {
        self::assertSame('unibet', $this->market->bestPrice('home')?->bookmakerId);
    }

    public function testBestPriceRespectsBookmakerSelection(): void
    {
        self::assertSame('betway', $this->market->bestPrice('home', ['bet365', 'betway'])?->bookmakerId);
    }

    public function testBestPriceIsNullWhenNoPrices(): void
    {
        self::assertNull($this->market->bestPrice('draw'));
    }

    public function testPriceForAndBookmakerIds(): void
    {
        self::assertSame(3.5, $this->market->priceFor('bet365', 'away')?->odds->decimal());
        self::assertNull($this->market->priceFor('unibet', 'away'));
        self::assertSame(['bet365', 'unibet', 'betway'], $this->market->bookmakerIds());
    }
}
