<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Support;

use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Http\HttpException;
use PlaidMonitor\Http\HttpResponse;

/**
 * Answers requests from handlers registered per URL path, and records every
 * request it receives.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: ?string, json: mixed}> */
    public array $requests = [];

    /** @var array<string, list<HttpResponse|HttpException|callable>> */
    private array $queues = [];

    /** @var array<string, HttpResponse|callable> */
    private array $defaults = [];

    /**
     * Queue one response for the next request to $path.
     */
    public function queue(string $path, HttpResponse|HttpException|callable $response): self
    {
        $this->queues[$path][] = $response;
        return $this;
    }

    /**
     * Response for every request to $path once its queue is empty.
     */
    public function always(string $path, HttpResponse|callable $response): self
    {
        $this->defaults[$path] = $response;
        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $request = [
            'method'  => $method,
            'url'     => $url,
            'path'    => $path,
            'headers' => $headers,
            'body'    => $body,
            'json'    => $body !== null ? json_decode($body, true) : null,
        ];
        $this->requests[] = $request;

        $response = !empty($this->queues[$path]) ? array_shift($this->queues[$path]) : ($this->defaults[$path] ?? null);

        if ($response === null) {
            throw new \LogicException("No fake response for {$method} {$path}");
        }
        if ($response instanceof HttpException) {
            throw $response;
        }
        if (is_callable($response)) {
            $response = $response($request);
        }

        return $response;
    }

    /**
     * @return list<array{method: string, url: string, path: string, headers: array<string, string>, body: ?string, json: mixed}>
     */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn(array $r) => $r['path'] === $path));
    }
}
