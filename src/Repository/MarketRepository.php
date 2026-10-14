<?php

declare(strict_types=1);

namespace BestOddsTable\Repository;

use BestOddsTable\Storage\KeyValueStore;

/**
 * Markets enabled by administrators.
 *
 * Stored shape: list<string> of market keys. Until the settings page saves
 * anything, every market in config/markets.php is enabled.
 */
final class MarketRepository
{
    public const OPTION = 'markets';

    /**
     * @param array<string, string> $catalogue Market key => label, in display order.
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly array $catalogue,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->catalogue;
    }

    /**
     * Enabled markets in catalogue order.
     *
     * @return array<string, string>
     */
    public function enabled(): array
    {
        $stored = $this->store->get(self::OPTION);

        if (!is_array($stored) || $stored === []) {
            return $this->catalogue;
        }

        return array_intersect_key($this->catalogue, array_flip(array_map('strval', $stored)));
    }

    public function isEnabled(string $key): bool
    {
        return isset($this->enabled()[$key]);
    }
}
