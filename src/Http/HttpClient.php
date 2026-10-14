<?php

declare(strict_types=1);

namespace BestOddsTable\Http;

use RuntimeException;

/**
 * Minimal HTTP contract for odds sources.
 *
 * Sources depend on this, not on wp_remote_get(), so they are unit tested
 * with recorded responses and never touch the network in tests.
 */
interface HttpClient
{
    /**
     * @throws RuntimeException When no response was received (DNS, timeout, TLS).
     *                          Any HTTP status, including errors, is a response.
     */
    public function get(string $url): HttpResponse;
}
