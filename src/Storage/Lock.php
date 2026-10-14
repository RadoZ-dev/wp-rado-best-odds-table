<?php

declare(strict_types=1);

namespace BestOddsTable\Storage;

/**
 * A named lock that only one process can hold at a time.
 *
 * Acquiring must be atomic: checking "is it free?" and then taking it in two
 * steps lets two cron runs that start together both proceed.
 */
interface Lock
{
    /**
     * Take the lock if it is free or its holder's time is up.
     *
     * @param int $ttl Seconds after which a lock left behind by a crashed run counts as free.
     */
    public function acquire(string $name, int $ttl): bool;

    public function release(string $name): void;
}
