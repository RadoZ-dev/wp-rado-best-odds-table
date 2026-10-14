<?php

declare(strict_types=1);

namespace BestOddsTable\Repository;

use BestOddsTable\Domain\Bookmaker;
use BestOddsTable\Storage\KeyValueStore;

/**
 * Bookmakers as configured by administrators.
 *
 * Until the settings page saves anything, the defaults from
 * config/bookmakers.php are used, all enabled.
 *
 * Stored shape: list<array{id, name, url, logo?, enabled}>
 */
final class BookmakerRepository
{
    public const OPTION = 'bookmakers';

    /**
     * @param list<array{id: string, name: string, url: string}> $defaults
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly array $defaults,
    ) {
    }

    /**
     * Every configured bookmaker with its enabled flag (for the settings page).
     *
     * @return list<array{bookmaker: Bookmaker, enabled: bool}>
     */
    public function all(): array
    {
        $rows = $this->store->get(self::OPTION);

        if (!is_array($rows) || $rows === []) {
            $rows = array_map(static fn (array $row): array => $row + ['enabled' => true], $this->defaults);
        }

        return array_map(static fn (array $row): array => [
            'bookmaker' => Bookmaker::fromArray($row),
            'enabled' => (bool) ($row['enabled'] ?? true),
        ], array_values($rows));
    }

    /**
     * Enabled bookmakers, optionally narrowed to a selection and in its order
     * (the block editor lets editors choose and order bookmakers).
     *
     * @param list<string>|null $onlyIds
     *
     * @return list<Bookmaker>
     */
    public function enabled(?array $onlyIds = null): array
    {
        $enabled = [];
        foreach ($this->all() as $row) {
            if ($row['enabled']) {
                $enabled[$row['bookmaker']->id] = $row['bookmaker'];
            }
        }

        if ($onlyIds === null || $onlyIds === []) {
            return array_values($enabled);
        }

        $selected = [];
        foreach ($onlyIds as $id) {
            if (isset($enabled[$id])) {
                $selected[] = $enabled[$id];
            }
        }

        return $selected;
    }
}
