<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * Pending paid refreshes. A pending request means the next
 * SYNC_UPDATES_AVAILABLE webhook for that integration is the result of a
 * refresh we paid for.
 */
interface RefreshRequestStore
{
    public function create(string $integrationId, string $itemId, \DateTimeImmutable $createdAt): string;

    /**
     * @return array{request_id: string, integration_id: string, item_id: string, created_at: string}|null
     */
    public function findPending(string $integrationId): ?array;

    /**
     * @param \DateTimeImmutable|null $createdBefore only delete requests created at or before this time
     */
    public function deleteForIntegration(string $integrationId, ?\DateTimeImmutable $createdBefore = null): int;

    public function delete(string $requestId): void;
}
