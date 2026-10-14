<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use InvalidArgumentException;
use JsonException;
use BestOddsTable\Domain\Event;

/**
 * Reads odds from a local JSON file.
 *
 * Used for development, automated tests and the public demo, so the demo
 * keeps working even if a scraped site blocks requests or changes markup.
 */
final class FixtureSource implements OddsSourceInterface
{
    public const ID = 'fixture';

    public function __construct(
        private readonly string $path,
        private readonly EventFactory $factory,
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

        $events = [];
        foreach ((array) ($payload['events'] ?? []) as $eventData) {
            try {
                $events[] = $this->factory->fromArray((array) $eventData, $marketKeys);
            } catch (InvalidArgumentException) {
                continue; // Skip broken events, keep the rest.
            }
        }

        return $events;
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
