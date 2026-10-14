<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use Closure;
use InvalidArgumentException;
use JsonException;
use BestOddsTable\Domain\Event;

/**
 * Reads odds from a local JSON file.
 *
 * Used for development, automated tests and the public demo, so the demo
 * keeps working even if a live source is unavailable or out of quota.
 *
 * With a clock, kick-off times roll forward so the earliest match is always
 * tomorrow; otherwise the demo would show only finished matches a few days
 * after the fixture was written. The shift is in whole days, so times keep
 * their time of day and only change once a day.
 */
final class FixtureSource implements OddsSourceInterface
{
    public const ID = 'fixture';

    private const DAY = 86400;

    /**
     * @param (Closure(): int)|null $clock Current Unix time; null keeps the file's dates as they are.
     */
    public function __construct(
        private readonly string $path,
        private readonly EventFactory $factory,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Local fixture (demo data)';
    }

    public function fetchEvents(array $marketKeys = []): array
    {
        if (!is_readable($this->path)) {
            throw new SourceException(sprintf('Fixture file "%s" is not readable.', $this->path));
        }

        try {
            $payload = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SourceException('Fixture file contains invalid JSON.', null, $e);
        }

        $rows = array_values(array_filter((array) ($payload['events'] ?? []), 'is_array'));

        $events = [];
        foreach ($this->rollForward($rows) as $eventData) {
            try {
                $events[] = $this->factory->fromArray($eventData, $marketKeys);
            } catch (InvalidArgumentException) {
                continue; // Skip broken events, keep the rest.
            }
        }

        return $events;
    }

    /**
     * Shift every kick-off by the same whole number of days, so the earliest
     * one falls tomorrow (UTC) and the gaps between matches stay as written.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function rollForward(array $rows): array
    {
        if ($this->clock === null) {
            return $rows;
        }

        $starts = array_filter(array_map(
            static fn (array $row): int|false => strtotime((string) ($row['starts_at'] ?? '')),
            $rows
        ));
        if ($starts === []) {
            return $rows;
        }

        $tomorrow = intdiv(($this->clock)(), self::DAY) + 1;
        $shift = ($tomorrow - intdiv(min($starts), self::DAY)) * self::DAY;

        foreach ($rows as $i => $row) {
            if (isset($starts[$i])) {
                $rows[$i]['starts_at'] = gmdate(DATE_ATOM, $starts[$i] + $shift);
            }
        }

        return $rows;
    }

    public function fetchEvent(string $eventId, array $marketKeys = []): ?Event
    {
        foreach ($this->fetchEvents($marketKeys) as $event) {
            if ($event->id === $eventId) {
                return $event;
            }
        }

        return null;
    }
}
