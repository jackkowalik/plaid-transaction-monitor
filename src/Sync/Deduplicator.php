<?php
declare(strict_types=1);

namespace PlaidMonitor\Sync;

use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\MerchantHistoryStore;
use PlaidMonitor\Storage\TransactionStore;

/**
 * Decides which synced transactions need evaluating, and stores them once
 * the run has been evaluated.
 *
 * Added transactions are skipped when their transaction_id is already
 * stored. The posted version of a pending transaction that was already
 * evaluated is stored without being evaluated again. It is recognised by
 * pending_transaction_id or, for institutions that don't link the two, by a
 * stored pending transaction with the same fingerprint (merchant, amount,
 * date, account) that Plaid removes in the same sync. Two separate identical
 * purchases are both kept, so duplicate_detection can see them.
 *
 * Modified transactions are evaluated again only if the merchant, amount,
 * date or account changed, so a category or status update does not repeat
 * an alert.
 */
class Deduplicator
{
    public function __construct(
        private readonly TransactionStore $transactions,
        private readonly MerchantHistoryStore $merchants
    ) {
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public static function fingerprint(array $transaction): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower(TransactionMapper::merchant($transaction)),
            number_format(abs((float) ($transaction['amount'] ?? 0)), 2, '.', ''),
            (string) ($transaction['date'] ?? ''),
            (string) ($transaction['account_id'] ?? ''),
        ]));
    }

    public function classify(Integration $integration, SyncResult $sync): Batch
    {
        $id = $integration->integrationId;
        $new = [];
        $modified = [];
        $storeOnly = [];
        $alreadyProcessed = 0;
        $seenThisRun = [];
        $removedThisRun = array_flip($sync->removed);

        foreach ($sync->added as $transaction) {
            if (!$integration->watchesAccount($transaction)) {
                continue;
            }

            $transactionId = (string) $transaction['transaction_id'];
            if (isset($seenThisRun[$transactionId]) || $this->transactions->has($id, $transactionId)) {
                $alreadyProcessed++;
                continue;
            }
            $seenThisRun[$transactionId] = true;

            $pendingId = $transaction['pending_transaction_id'] ?? null;
            $isPostedVersion = $pendingId !== null
                ? $this->transactions->has($id, (string) $pendingId)
                : $this->replacesRemovedPending($id, $transaction, $removedThisRun);

            if ($isPostedVersion) {
                $storeOnly[] = $transaction;
                continue;
            }

            $new[] = $transaction;
        }

        foreach ($sync->modified as $transaction) {
            if (!$integration->watchesAccount($transaction)) {
                continue;
            }

            $stored = $this->transactions->fingerprintOf($id, (string) $transaction['transaction_id']);
            if ($stored !== null && $stored === self::fingerprint($transaction)) {
                $storeOnly[] = $transaction;
            } else {
                $modified[] = $transaction;
            }
        }

        $removed = array_values(array_filter(
            $sync->removed,
            fn(string $transactionId) => $this->transactions->has($id, $transactionId)
        ));

        return new Batch($new, $modified, $storeOnly, $removed, $alreadyProcessed);
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<string, int> $removedThisRun
     */
    private function replacesRemovedPending(string $integrationId, array $transaction, array $removedThisRun): bool
    {
        if (($transaction['pending'] ?? false) || $removedThisRun === []) {
            return false;
        }

        foreach ($this->transactions->pendingIdsWithFingerprint($integrationId, self::fingerprint($transaction)) as $pendingId) {
            if (isset($removedThisRun[$pendingId])) {
                return true;
            }
        }

        return false;
    }

    public function persist(Integration $integration, Batch $batch, \DateTimeImmutable $now): void
    {
        $id = $integration->integrationId;

        foreach (array_merge($batch->new, $batch->modified, $batch->storeOnly) as $transaction) {
            $this->transactions->save($id, $transaction, self::fingerprint($transaction), $now);
        }

        foreach ($batch->new as $transaction) {
            $merchant = TransactionMapper::merchant($transaction);
            if ($merchant !== 'Unknown') {
                $this->merchants->record($id, $merchant, (string) $transaction['date']);
            }
        }

        foreach ($batch->removed as $transactionId) {
            $this->transactions->delete($id, $transactionId);
        }
    }
}
