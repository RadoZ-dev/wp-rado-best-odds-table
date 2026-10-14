<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * Durable storage that survives cache flushes (options table in WordPress).
 */
interface KeyValueStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;
}
