<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

interface AlertStore
{
    public const KIND_FRAUD = 'fraud_alert';
    public const KIND_ACCOUNT_ERROR = 'account_error';

    /**
     * @param array<string, mixed> $payload
     * @return string alert ID
     */
    public function create(
        string $kind,
        string $itemId,
        ?string $integrationId,
        string $severity,
        array $payload,
        \DateTimeImmutable $createdAt
    ): string;
}
