<?php

declare(strict_types=1);

namespace BestOddsTable\Fetch;

/**
 * Outcome of one fetch run. Handy for logs, WP-CLI output and tests.
 */
final class FetchReport
{
    public const OK = 'ok';
    public const LOCKED = 'locked';
    public const BACKING_OFF = 'backing_off';
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $status,
        public readonly int $fetched = 0,
        public readonly int $failed = 0,
        public readonly int $deferred = 0,
    ) {
    }

    /**
     * @return array{at: int, status: string, fetched: int, failed: int, deferred: int}
     */
    public function toArray(int $at): array
    {
        return [
            'at' => $at,
            'status' => $this->status,
            'fetched' => $this->fetched,
            'failed' => $this->failed,
            'deferred' => $this->deferred,
        ];
    }
}
