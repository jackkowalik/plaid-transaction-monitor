<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PDO;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\IntegrationStore;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\PlainTokenCipher;
use PlaidMonitor\Storage\TokenCipher;
use PlaidMonitor\Time\Time;

class PdoIntegrationStore extends PdoStore implements IntegrationStore
{
    private const COLUMNS = 'integration_id, item_id, account_id, name, interval_minutes, status, rules,
        alert_webhook_url, alert_webhook_secret, alert_email, slack_webhook_url, sync_cursor,
        last_processing_started_at, last_processed_at, baseline_complete';

    private TokenCipher $cipher;

    public function __construct(PDO $pdo, ?TokenCipher $cipher = null)
    {
        parent::__construct($pdo);
        $this->cipher = $cipher ?? new PlainTokenCipher();
    }

    public function saveItem(Item $item): void
    {
        $params = [
            'item_id'          => $item->itemId,
            'access_token'     => $this->cipher->encrypt($item->accessToken),
            'institution_name' => $item->institutionName,
        ];

        if ($this->findItem($item->itemId) !== null) {
            $this->execute(
                'UPDATE items SET access_token = :access_token, institution_name = :institution_name
                 WHERE item_id = :item_id',
                $params
            );
            return;
        }

        $params['created_at'] = Time::format(new \DateTimeImmutable('now'));
        $this->execute(
            'INSERT INTO items (item_id, access_token, institution_name, created_at)
             VALUES (:item_id, :access_token, :institution_name, :created_at)',
            $params
        );
    }

    public function findItem(string $itemId): ?Item
    {
        $row = $this->fetchRow(
            'SELECT item_id, access_token, institution_name FROM items WHERE item_id = :item_id',
            ['item_id' => $itemId]
        );

        return $row === null
            ? null
            : new Item($row['item_id'], $this->cipher->decrypt($row['access_token']), $row['institution_name']);
    }

    public function deleteItem(string $itemId): void
    {
        $ids = array_column(
            $this->fetchAll('SELECT integration_id FROM integrations WHERE item_id = :item_id', ['item_id' => $itemId]),
            'integration_id'
        );

        foreach ($ids as $integrationId) {
            $this->delete($integrationId);
        }

        $this->execute('DELETE FROM items WHERE item_id = :item_id', ['item_id' => $itemId]);
    }

    public function saveIntegration(Integration $integration): void
    {
        $now = Time::format(new \DateTimeImmutable('now'));
        $params = [
            'integration_id'       => $integration->integrationId,
            'item_id'              => $integration->itemId,
            'account_id'           => $integration->accountId,
            'name'                 => $integration->name,
            'interval_minutes'     => $integration->intervalMinutes,
            'status'               => $integration->status,
            'rules'                => $this->encodeJson($integration->rules),
            'alert_webhook_url'    => $integration->alertWebhookUrl,
            'alert_webhook_secret' => $integration->alertWebhookSecret,
            'alert_email'          => $integration->alertEmail,
            'slack_webhook_url'    => $integration->slackWebhookUrl,
            'updated_at'           => $now,
        ];

        $exists = $this->fetchRow(
            'SELECT 1 AS found FROM integrations WHERE integration_id = :integration_id',
            ['integration_id' => $integration->integrationId]
        ) !== null;

        if ($exists) {
            $this->execute(
                'UPDATE integrations SET item_id = :item_id, account_id = :account_id, name = :name,
                    interval_minutes = :interval_minutes, status = :status, rules = :rules,
                    alert_webhook_url = :alert_webhook_url, alert_webhook_secret = :alert_webhook_secret,
                    alert_email = :alert_email, slack_webhook_url = :slack_webhook_url, updated_at = :updated_at
                 WHERE integration_id = :integration_id',
                $params
            );
            return;
        }

        $params += [
            'sync_cursor'                => $integration->cursor,
            'last_processing_started_at' => $integration->lastProcessingStartedAt ? Time::format($integration->lastProcessingStartedAt) : null,
            'last_processed_at'          => $integration->lastProcessedAt ? Time::format($integration->lastProcessedAt) : null,
            'baseline_complete'          => $integration->baselineComplete ? 1 : 0,
            'created_at'                 => $now,
        ];
        $this->execute(
            'INSERT INTO integrations (integration_id, item_id, account_id, name, interval_minutes, status, rules,
                alert_webhook_url, alert_webhook_secret, alert_email, slack_webhook_url, sync_cursor,
                last_processing_started_at, last_processed_at, baseline_complete, created_at, updated_at)
             VALUES (:integration_id, :item_id, :account_id, :name, :interval_minutes, :status, :rules,
                :alert_webhook_url, :alert_webhook_secret, :alert_email, :slack_webhook_url, :sync_cursor,
                :last_processing_started_at, :last_processed_at, :baseline_complete, :created_at, :updated_at)',
            $params
        );
    }

    public function find(string $integrationId): ?Integration
    {
        $row = $this->fetchRow(
            'SELECT ' . self::COLUMNS . ' FROM integrations WHERE integration_id = :integration_id',
            ['integration_id' => $integrationId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findActiveByItem(string $itemId): array
    {
        $rows = $this->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM integrations
             WHERE item_id = :item_id AND status = :status
             ORDER BY created_at ASC, integration_id ASC',
            ['item_id' => $itemId, 'status' => Integration::STATUS_ACTIVE]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function findActiveWithIntervalBelow(int $minutes): array
    {
        $rows = $this->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM integrations
             WHERE status = :status AND interval_minutes < :minutes
             ORDER BY item_id ASC, created_at ASC, integration_id ASC',
            ['status' => Integration::STATUS_ACTIVE, 'minutes' => $minutes]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function delete(string $integrationId): void
    {
        $params = ['integration_id' => $integrationId];
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach (['transactions', 'merchant_history', 'refresh_requests', 'integrations'] as $table) {
                $this->execute("DELETE FROM {$table} WHERE integration_id = :integration_id", $params);
            }
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteByAccount(string $itemId, string $accountId): int
    {
        $ids = array_column($this->fetchAll(
            'SELECT integration_id FROM integrations WHERE item_id = :item_id AND account_id = :account_id',
            ['item_id' => $itemId, 'account_id' => $accountId]
        ), 'integration_id');

        foreach ($ids as $integrationId) {
            $this->delete($integrationId);
        }

        return count($ids);
    }

    public function setProcessingStarted(string $integrationId, ?\DateTimeImmutable $startedAt): void
    {
        $this->execute(
            'UPDATE integrations SET last_processing_started_at = :started_at, updated_at = :updated_at
             WHERE integration_id = :integration_id',
            [
                'started_at'     => $startedAt ? Time::format($startedAt) : null,
                'updated_at'     => Time::format(new \DateTimeImmutable('now')),
                'integration_id' => $integrationId,
            ]
        );
    }

    public function recordProcessed(
        string $integrationId,
        string $cursor,
        string $source,
        int $transactionCount,
        int $alertCount,
        \DateTimeImmutable $processedAt,
        bool $baselineComplete
    ): void {
        $sourceColumn = $source === 'paid_refresh' ? 'last_paid_refresh_at' : 'last_automatic_update_at';
        $at = Time::format($processedAt);

        $this->execute(
            "UPDATE integrations SET
                sync_cursor = :cursor,
                last_processed_at = :processed_at,
                {$sourceColumn} = :source_at,
                baseline_complete = :baseline_complete,
                total_transactions_processed = total_transactions_processed + :transaction_count,
                total_alerts_generated = total_alerts_generated + :alert_count,
                updated_at = :updated_at
             WHERE integration_id = :integration_id",
            [
                'cursor'            => $cursor,
                'processed_at'      => $at,
                'source_at'         => $at,
                'baseline_complete' => $baselineComplete ? 1 : 0,
                'transaction_count' => $transactionCount,
                'alert_count'       => $alertCount,
                'updated_at'        => $at,
                'integration_id'    => $integrationId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Integration
    {
        return new Integration(
            integrationId: $row['integration_id'],
            itemId: $row['item_id'],
            accountId: $row['account_id'],
            intervalMinutes: (int) $row['interval_minutes'],
            rules: array_values($this->decodeJson($row['rules'])),
            status: $row['status'],
            name: $row['name'],
            alertWebhookUrl: $row['alert_webhook_url'],
            alertWebhookSecret: $row['alert_webhook_secret'],
            alertEmail: $row['alert_email'],
            slackWebhookUrl: $row['slack_webhook_url'],
            cursor: $row['sync_cursor'],
            lastProcessingStartedAt: Time::parse($row['last_processing_started_at']),
            lastProcessedAt: Time::parse($row['last_processed_at']),
            baselineComplete: (bool) $row['baseline_complete'],
        );
    }
}
