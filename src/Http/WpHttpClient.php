<?php

declare(strict_types=1);

namespace BestOddsTable\Http;

use RuntimeException;

/**
 * HttpClient on the WordPress HTTP API, so site-wide proxy settings,
 * SSL certificates and the pre_http_request filter all apply.
 */
final class WpHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function get(string $url): HttpResponse
    {
        $response = wp_remote_get($url, [
            'timeout' => $this->timeoutSeconds,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
            $headers
        );
    }
}
