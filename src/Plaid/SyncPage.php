<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

final class SyncPage
{
    /**
     * @param list<array<string, mixed>> $added    normalized transactions
     * @param list<array<string, mixed>> $modified normalized transactions
     * @param list<string>               $removed  transaction IDs
     * @param string|null                $updateStatus Plaid's transactions_update_status
     */
    public function __construct(
        public readonly array $added,
        public readonly array $modified,
        public readonly array $removed,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
        public readonly ?string $updateStatus = null
    ) {
    }
}
