<?php
declare(strict_types=1);

namespace PlaidMonitor\Http;

interface HttpClient
{
    /**
     * Send a request. Non-2xx responses are returned, not thrown.
     *
     * @param array<string, string> $headers
     * @throws HttpException when the request could not be sent at all
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 30
    ): HttpResponse;
}
