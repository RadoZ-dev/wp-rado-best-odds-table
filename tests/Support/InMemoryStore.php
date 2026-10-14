<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Support;

use BestOddsTable\Storage\CacheInterface;
use BestOddsTable\Storage\KeyValueStore;

/**
 * Test double for both storage contracts. TTLs are recorded, not enforced;
 * tests simulate expiry by calling delete() or flush().
 */
final class InMemoryStore implements KeyValueStore, CacheInterface
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key], $this->ttls[$key]);
    }

    public function flush(): void
    {
        $this->data = [];
        $this->ttls = [];
    }
}
