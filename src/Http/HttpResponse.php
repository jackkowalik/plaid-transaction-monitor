<?php
declare(strict_types=1);

namespace PlaidMonitor\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
