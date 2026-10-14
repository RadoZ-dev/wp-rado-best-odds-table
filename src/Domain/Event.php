<?php

declare(strict_types=1);

namespace BestOddsTable\Domain;

use DateTimeImmutable;

/**
 * A sporting event and the markets offered on it.
 */
final class Event
{
    /**
     * Allowed event ids. Ids end up in storage keys, the "event|market" watch
     * key and a source's request URLs, so only plain slug characters are
     * accepted: no "|", "/", "." or whitespace, and a bounded length.
     */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,100}$/';

    /**
     * @param array<string, Market> $markets Keyed by market key.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sport,
        public readonly string $competition,
        public readonly string $home,
        public readonly string $away,
        public readonly DateTimeImmutable $startsAt,
        private readonly array $markets,
    ) {
    }

    public function name(): string
    {
        return sprintf('%s v %s', $this->home, $this->away);
    }

    /**
     * @return array<string, Market>
     */
    public function markets(): array
    {
        return $this->markets;
    }

    public function market(string $key): ?Market
    {
        return $this->markets[$key] ?? null;
    }

    /**
     * Normalised array (see EventFactory), optionally limited to some markets.
     *
     * @param list<string> $marketKeys Empty means all markets.
     *
     * @return array<string, mixed>
     */
    public function toArray(array $marketKeys = []): array
    {
        $markets = [];
        foreach ($this->markets as $key => $market) {
            if ($marketKeys === [] || in_array($key, $marketKeys, true)) {
                $markets[] = $market->toArray();
            }
        }

        return [
            'id' => $this->id,
            'sport' => $this->sport,
            'competition' => $this->competition,
            'home' => $this->home,
            'away' => $this->away,
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'markets' => $markets,
        ];
    }

    /**
     * Lightweight summary for the block editor's event picker.
     *
     * @return array{id: string, name: string, sport: string, competition: string, starts_at: string, markets: array<string, string>}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name(),
            'sport' => $this->sport,
            'competition' => $this->competition,
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'markets' => array_map(static fn (Market $market): string => $market->label, $this->markets),
        ];
    }
}
