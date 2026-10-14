<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use InvalidArgumentException;
use BestOddsTable\Conversion\OddsConverter;
use BestOddsTable\Source\EventFactory;
use BestOddsTable\Source\FixtureSource;
use BestOddsTable\Source\OddsSourceInterface;
use BestOddsTable\Source\SourceException;
use BestOddsTable\Source\SourceRegistry;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/odds.json';

    private function fixtureSource(string $path = self::FIXTURE): FixtureSource
    {
        return new FixtureSource($path, new EventFactory(new OddsConverter()));
    }

    public function testFixtureLoadsEventsWithTenBookmakers(): void
    {
        $events = $this->fixtureSource()->fetchEvents();

        self::assertCount(3, $events);
        self::assertSame('Arsenal v Chelsea', $events[0]->name());

        $matchResult = $events[0]->market('match_result');
        self::assertNotNull($matchResult);
        self::assertCount(10, $matchResult->bookmakerIds());
        self::assertNotNull($matchResult->bestPrice('home'));
    }

    public function testMarketFilter(): void
    {
        $events = $this->fixtureSource()->fetchEvents(['btts']);

        self::assertSame(['btts'], array_keys($events[0]->markets()));
    }

    public function testMissingFixtureThrowsSourceException(): void
    {
        $this->expectException(SourceException::class);
        $this->fixtureSource('/nonexistent/odds.json')->fetchEvents();
    }

    public function testFactorySkipsMalformedPricesButKeepsMarket(): void
    {
        $factory = new EventFactory(new OddsConverter());
        $event = $factory->fromArray([
            'id' => 'x', 'home' => 'A', 'away' => 'B', 'starts_at' => '2026-10-18T14:00:00Z',
            'markets' => [[
                'key' => 'match_result',
                'outcomes' => ['home' => 'A', 'away' => 'B'],
                'prices' => [
                    'good' => ['home' => '2/1', 'away' => 'garbage'],
                    'bad' => ['home' => 'n/a', 'unknown_outcome' => '3/1'],
                ],
            ]],
        ]);

        $prices = $event->market('match_result')?->prices() ?? [];
        self::assertCount(1, $prices);
        self::assertSame('good', $prices[0]->bookmakerId);
    }

    public function testRegistryIsLazyAndCachesInstances(): void
    {
        $registry = new SourceRegistry();
        $calls = 0;
        $registry->register('fixture', function () use (&$calls): OddsSourceInterface {
            $calls++;
            return $this->fixtureSource();
        });

        self::assertSame(0, $calls);
        self::assertSame($registry->get('fixture'), $registry->get('fixture'));
        self::assertSame(1, $calls);
    }

    public function testRegistryRejectsUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SourceRegistry())->get('nope');
    }
}
