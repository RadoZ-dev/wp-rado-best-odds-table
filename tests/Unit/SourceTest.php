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

    public function testWithoutClockFixtureDatesAreKept(): void
    {
        self::assertSame('2026-10-18 14:00', $this->fixtureSource()->fetchEvents()[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testWithClockEarliestMatchIsTomorrowAndGapsAreKept(): void
    {
        $at = static fn (string $time): \Closure => static fn (): int => (int) strtotime($time);
        $source = static fn (\Closure $clock): FixtureSource =>
            new FixtureSource(self::FIXTURE, new EventFactory(new OddsConverter()), $clock);

        $starts = array_map(
            static fn ($event): string => $event->startsAt->format('Y-m-d H:i'),
            $source($at('2026-11-03T09:00:00Z'))->fetchEvents()
        );

        // Fixture: 18 Oct 14:00, 18 Oct 16:30, 19 Oct 19:00. Shifted to start on 4 Nov.
        self::assertSame(['2026-11-04 14:00', '2026-11-04 16:30', '2026-11-05 19:00'], $starts);

        // Stable within a day, so kick-off times do not drift between page views.
        $later = $source($at('2026-11-03T23:59:00Z'))->fetchEvents();
        self::assertSame('2026-11-04 14:00', $later[0]->startsAt->format('Y-m-d H:i'));

        // Ids do not change, so blocks keep pointing at the same matches.
        self::assertSame('ars-che-2026-10-18', $later[0]->id);
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

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badEvents(): array
    {
        $valid = ['id' => 'x', 'home' => 'A', 'away' => 'B', 'starts_at' => '2026-10-18T14:00:00Z'];

        return [
            'malformed date' => [['starts_at' => 'not a date'] + $valid],
            'separator in id' => [['id' => 'a|b'] + $valid],
            'path in id' => [['id' => '../admin'] + $valid],
            'overlong id' => [['id' => str_repeat('a', 101)] + $valid],
        ];
    }

    /**
     * @dataProvider badEvents
     *
     * @param array<string, mixed> $data
     */
    public function testFactoryRejectsBadEventsWithInvalidArgumentException(array $data): void
    {
        // Callers skip an event on InvalidArgumentException; anything else would crash a fetch run.
        $this->expectException(InvalidArgumentException::class);
        (new EventFactory(new OddsConverter()))->fromArray($data);
    }

    public function testFactorySkipsMarketsWithInvalidKeys(): void
    {
        $event = (new EventFactory(new OddsConverter()))->fromArray([
            'id' => 'x', 'home' => 'A', 'away' => 'B', 'starts_at' => '2026-10-18T14:00:00Z',
            'markets' => [
                ['key' => 'match_result', 'outcomes' => ['home' => 'A']],
                ['key' => 'Bad Key|x', 'outcomes' => ['home' => 'A']],
            ],
        ]);

        self::assertSame(['match_result'], array_keys($event->markets()));
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
