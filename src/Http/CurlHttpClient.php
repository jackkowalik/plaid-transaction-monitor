<?php
declare(strict_types=1);

namespace PlaidMonitor\Http;

class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly string $userAgent = 'plaid-transaction-monitor/1.0',
        private readonly int $connectTimeoutSeconds = 10
    ) {
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 30
    ): HttpResponse {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Failed to initialize cURL');
        }

        $headerLines = ['User-Agent: ' . $this->userAgent];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new HttpException("Request to {$this->hostOf($url)} failed: {$error}");
        }

        return new HttpResponse($status, (string) $responseBody);
    }

    private function hostOf(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: 'unknown host';
    }
}
