<?php

declare(strict_types=1);

namespace BestOddsTable\Source;

use RuntimeException;

/**
 * Thrown when a source cannot deliver usable odds.
 *
 * $retryAfter carries a rate-limit hint (seconds) so the fetcher can back off
 * instead of hammering the provider.
 */
final class SourceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function rateLimited(string $sourceId, int $retryAfter): self
    {
        return new self(sprintf('Source "%s" is rate limited.', $sourceId), $retryAfter);
    }
}
