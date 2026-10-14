<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * CacheInterface on the Transients API.
 *
 * With a persistent object cache (Redis, Memcached) WordPress stores
 * transients there automatically; without one they fall back to the
 * options table. Either way this class does not need to know.
 */
final class TransientCache implements CacheInterface
{
    public function __construct(private readonly string $prefix = 'oc_')
    {
    }

    public function get(string $key): mixed
    {
        $value = get_transient($this->prefix . $key);

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        set_transient($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient($this->prefix . $key);
    }
}
