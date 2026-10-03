<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Storage\Ids;
use PlaidMonitor\Storage\RefreshRequestStore;
use PlaidMonitor\Time\Time;

class PdoRefreshRequestStore extends PdoStore implements RefreshRequestStore
{
    public function create(string $integrationId, string $itemId, \DateTimeImmutable $createdAt): string
    {
        $requestId = Ids::generate('rr');

        $this->execute(
            'INSERT INTO refresh_requests (request_id, integration_id, item_id, created_at)
             VALUES (:request_id, :integration_id, :item_id, :created_at)',
            [
                'request_id'     => $requestId,
                'integration_id' => $integrationId,
                'item_id'        => $itemId,
                'created_at'     => Time::format($createdAt),
            ]
        );

        return $requestId;
    }

    public function findPending(string $integrationId): ?array
    {
        return $this->fetchRow(
            'SELECT request_id, integration_id, item_id, created_at FROM refresh_requests
             WHERE integration_id = :integration_id
             ORDER BY created_at DESC
             LIMIT 1',
            ['integration_id' => $integrationId]
        );
    }

    public function deleteForIntegration(string $integrationId, ?\DateTimeImmutable $createdBefore = null): int
    {
        if ($createdBefore === null) {
            return $this->execute(
                'DELETE FROM refresh_requests WHERE integration_id = :integration_id',
                ['integration_id' => $integrationId]
            )->rowCount();
        }

        return $this->execute(
            'DELETE FROM refresh_requests WHERE integration_id = :integration_id AND created_at <= :created_before',
            ['integration_id' => $integrationId, 'created_before' => Time::format($createdBefore)]
        )->rowCount();
    }

    public function delete(string $requestId): void
    {
        $this->execute('DELETE FROM refresh_requests WHERE request_id = :request_id', ['request_id' => $requestId]);
    }
}
