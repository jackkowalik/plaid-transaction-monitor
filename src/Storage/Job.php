<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

final class Job
{
    /**
     * @param array<string, mixed> $payload the webhook body from Plaid
     */
    public function __construct(
        public readonly int $id,
        public readonly string $itemId,
        public readonly string $webhookType,
        public readonly string $webhookCode,
        public readonly array $payload,
        public readonly int $attempts
    ) {
    }
}
