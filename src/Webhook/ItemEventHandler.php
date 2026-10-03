<?php
declare(strict_types=1);

namespace PlaidMonitor\Webhook;

use PlaidMonitor\Alerts\AlertDispatcher;
use PlaidMonitor\Storage\IntegrationStore;
use Psr\Log\LoggerInterface;

/**
 * Handles ITEM webhooks: connection problems are reported as account.error
 * alerts, and revocations remove the affected integrations.
 */
class ItemEventHandler
{
    public const ACCOUNT_ERROR_CODES = ['PENDING_EXPIRATION', 'PENDING_DISCONNECT', 'ERROR'];
    public const REVOCATION_CODES = ['USER_PERMISSION_REVOKED', 'USER_ACCOUNT_REVOKED'];

    public function __construct(
        private readonly IntegrationStore $integrations,
        private readonly AlertDispatcher $alerts,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(string $itemId, string $webhookCode, array $payload): void
    {
        if (in_array($webhookCode, self::ACCOUNT_ERROR_CODES, true)) {
            $this->accountError($itemId, $webhookCode, $payload);
        } elseif (in_array($webhookCode, self::REVOCATION_CODES, true)) {
            $this->revoked($itemId, $webhookCode, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function accountError(string $itemId, string $webhookCode, array $payload): void
    {
        $integrations = $this->integrations->findActiveByItem($itemId);
        if ($integrations === []) {
            $this->logger->info('Item event for an item with no active integrations', [
                'item_id'      => $itemId,
                'webhook_code' => $webhookCode,
            ]);
            return;
        }

        $this->alerts->accountError($itemId, $this->integrations->findItem($itemId), $integrations, $webhookCode, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function revoked(string $itemId, string $webhookCode, array $payload): void
    {
        if ($webhookCode === 'USER_PERMISSION_REVOKED') {
            $this->integrations->deleteItem($itemId);
            $this->logger->info('Item revoked; removed it and its integrations', ['item_id' => $itemId]);
            return;
        }

        $accountId = $payload['account_id'] ?? null;
        if (!is_string($accountId) || $accountId === '') {
            $this->logger->warning('USER_ACCOUNT_REVOKED without an account_id', ['item_id' => $itemId]);
            return;
        }

        $removed = $this->integrations->deleteByAccount($itemId, $accountId);
        $this->logger->info('Account revoked; removed its integrations', [
            'item_id' => $itemId,
            'removed' => $removed,
        ]);
    }
}
