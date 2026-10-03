<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

interface TransactionStore
{
    public function has(string $integrationId, string $transactionId): bool;

    /**
     * IDs of stored pending transactions with this fingerprint.
     *
     * @return list<string>
     */
    public function pendingIdsWithFingerprint(string $integrationId, string $fingerprint): array;

    /**
     * Fingerprint of a stored transaction, or null when it is not stored.
     */
    public function fingerprintOf(string $integrationId, string $transactionId): ?string;

    /**
     * Insert or replace a stored transaction.
     *
     * @param array<string, mixed> $transaction normalized transaction
     */
    public function save(string $integrationId, array $transaction, string $fingerprint, \DateTimeImmutable $now): void;

    public function delete(string $integrationId, string $transactionId): bool;

    /**
     * Stored transactions dated on or after $fromDate, newest first. Amounts
     * keep Plaid's sign: positive is money leaving the account.
     *
     * @return list<array{transaction_id: string, account_id: string, date: string, amount: float, currency: string, merchant: string, name: string, category: ?string}>
     */
    public function since(string $integrationId, string $fromDate, int $limit = 5000): array;

    /**
     * @param list<array<string, mixed>> $triggeredRules
     */
    public function markFlagged(string $integrationId, string $transactionId, array $triggeredRules): void;
}
