<?php

declare(strict_types=1);

namespace BestOddsTable\Http;

final class HttpResponse
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers Header name => value; names are case-insensitive.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
