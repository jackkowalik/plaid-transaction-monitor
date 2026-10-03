<?php
declare(strict_types=1);

namespace PlaidMonitor\Sync;

final class SyncResult
{
    /**
     * @param list<array<string, mixed>> $added
     * @param list<array<string, mixed>> $modified
     * @param list<string> $removed transaction IDs
     * @param bool $complete false when the page limit was hit; the next run continues from $nextCursor
     * @param string|null $updateStatus Plaid's transactions_update_status from the last page
     */
    public function __construct(
        public readonly array $added,
        public readonly array $modified,
        public readonly array $removed,
        public readonly string $nextCursor,
        public readonly int $pages,
        public readonly bool $complete,
        public readonly ?string $updateStatus = null
    ) {
    }

    /**
     * False while Plaid is still pulling the item's history. A missing or
     * unknown status counts as complete.
     */
    public function historyComplete(): bool
    {
        return !in_array($this->updateStatus, ['NOT_READY', 'INITIAL_UPDATE_COMPLETE'], true);
    }
}
