<?php
declare(strict_types=1);

namespace PlaidMonitor\Sync;

use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Plaid\PlaidException;
use Psr\Log\LoggerInterface;

/**
 * Pulls every available page of /transactions/sync from a saved cursor.
 *
 * If Plaid reports TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION, the whole
 * pull restarts from the original cursor, as Plaid requires.
 */
class TransactionSyncer
{
    private const MAX_PAGES = 100;
    private const MAX_RESTARTS = 3;

    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly LoggerInterface $logger
    ) {
    }

    public function syncAll(string $accessToken, ?string $cursor): SyncResult
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->pull($accessToken, $cursor);
            } catch (PlaidException $e) {
                if (!$e->isMutationDuringPagination() || $attempt >= self::MAX_RESTARTS) {
                    throw $e;
                }
                $this->logger->info('Transactions changed during pagination, restarting from the saved cursor', [
                    'attempt' => $attempt + 1,
                ]);
            }
        }
    }

    private function pull(string $accessToken, ?string $cursor): SyncResult
    {
        $added = [];
        $modified = [];
        $removed = [];
        $current = $cursor;
        $pages = 0;

        do {
            $page = $this->plaid->syncTransactions($accessToken, $current);
            $pages++;

            array_push($added, ...$page->added);
            array_push($modified, ...$page->modified);
            array_push($removed, ...$page->removed);
            $current = $page->nextCursor;

            if ($page->hasMore && $pages >= self::MAX_PAGES) {
                $this->logger->warning('Sync page limit reached; the rest will be pulled on the next run', [
                    'pages' => $pages,
                ]);
                return new SyncResult($added, $modified, $removed, $current, $pages, false, $page->updateStatus);
            }
        } while ($page->hasMore);

        return new SyncResult($added, $modified, $removed, (string) $current, $pages, true, $page->updateStatus);
    }
}
