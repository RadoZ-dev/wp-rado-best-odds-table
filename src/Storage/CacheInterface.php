<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * Fast, expiring storage. May be emptied at any time, so callers must
 * always have a fallback.
 */
interface CacheInterface
{
    /**
     * @return mixed Null on a miss.
     */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    public function delete(string $key): void;
}
