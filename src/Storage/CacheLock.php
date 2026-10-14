<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * Lock on any CacheInterface. Not atomic (check, then set), so it only
 * suits single-process use such as tests; WordPress uses WpOptionLock.
 */
final class CacheLock implements Lock
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function acquire(string $name, int $ttl): bool
    {
        if ($this->cache->get($name) !== null) {
            return false;
        }

        $this->cache->set($name, time(), $ttl);

        return true;
    }

    public function release(string $name): void
    {
        $this->cache->delete($name);
    }
}
