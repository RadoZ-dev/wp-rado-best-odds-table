<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * KeyValueStore on the options table.
 *
 * Options are written with autoload disabled: odds snapshots must not be
 * loaded into memory on every request of the site.
 */
final class WpOptionStore implements KeyValueStore
{
    public function __construct(private readonly string $prefix = 'best_odds_table_')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return get_option($this->prefix . $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        update_option($this->prefix . $key, $value, false);
    }

    public function delete(string $key): void
    {
        delete_option($this->prefix . $key);
    }
}
