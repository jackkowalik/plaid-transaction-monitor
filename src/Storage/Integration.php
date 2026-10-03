<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * A monitored bank account: which item and account to watch, how often,
 * which rules to run, and where alerts go.
 *
 * The last four fields are sync state owned by the monitor. They are read
 * back from storage, and IntegrationStore::saveIntegration() ignores them
 * when updating an existing integration.
 */
final class Integration
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    /**
     * @param string|null $accountId Plaid account_id to monitor, or null for every account on the item
     * @param list<array<string, mixed>> $rules
     */
    public function __construct(
        public readonly string $integrationId,
        public readonly string $itemId,
        public readonly ?string $accountId,
        public readonly int $intervalMinutes,
        public readonly array $rules = [],
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?string $name = null,
        public readonly ?string $alertWebhookUrl = null,
        public readonly ?string $alertWebhookSecret = null,
        public readonly ?string $alertEmail = null,
        public readonly ?string $slackWebhookUrl = null,
        public readonly ?string $cursor = null,
        public readonly ?\DateTimeImmutable $lastProcessingStartedAt = null,
        public readonly ?\DateTimeImmutable $lastProcessedAt = null,
        public readonly bool $baselineComplete = false
    ) {
        if ($intervalMinutes < 1) {
            throw new \InvalidArgumentException('intervalMinutes must be at least 1');
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(array $rule) => ($rule['enabled'] ?? false) === true
        ));
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public function watchesAccount(array $transaction): bool
    {
        return $this->accountId === null || ($transaction['account_id'] ?? null) === $this->accountId;
    }
}
