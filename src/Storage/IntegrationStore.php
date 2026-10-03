<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

interface IntegrationStore
{
    public function saveItem(Item $item): void;

    public function findItem(string $itemId): ?Item;

    /**
     * Remove an item and every integration on it.
     */
    public function deleteItem(string $itemId): void;

    /**
     * Insert a new integration, or update the configuration of an existing
     * one. Sync state (cursor, processing timestamps, baseline flag) is only
     * written on insert, so saving an edited integration never rewinds it.
     */
    public function saveIntegration(Integration $integration): void;

    public function find(string $integrationId): ?Integration;

    /**
     * @return list<Integration> active integrations on the item
     */
    public function findActiveByItem(string $itemId): array;

    /**
     * @return list<Integration> active integrations with an interval below $minutes
     */
    public function findActiveWithIntervalBelow(int $minutes): array;

    public function delete(string $integrationId): void;

    /**
     * Remove integrations that watch one specific account on an item.
     *
     * @return int number of integrations removed
     */
    public function deleteByAccount(string $itemId, string $accountId): int;

    public function setProcessingStarted(string $integrationId, ?\DateTimeImmutable $startedAt): void;

    /**
     * Save the cursor and counters after a processing run.
     *
     * @param string $source 'paid_refresh' or 'automatic_update'
     * @param bool $baselineComplete whether the item's history has been stored as the baseline
     */
    public function recordProcessed(
        string $integrationId,
        string $cursor,
        string $source,
        int $transactionCount,
        int $alertCount,
        \DateTimeImmutable $processedAt,
        bool $baselineComplete
    ): void;
}
