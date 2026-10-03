<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Storage\TransactionStore;
use PlaidMonitor\Time\Time;

class PdoTransactionStore extends PdoStore implements TransactionStore
{
    public function has(string $integrationId, string $transactionId): bool
    {
        return $this->fetchRow(
            'SELECT 1 AS found FROM transactions WHERE integration_id = :integration_id AND transaction_id = :transaction_id',
            ['integration_id' => $integrationId, 'transaction_id' => $transactionId]
        ) !== null;
    }

    public function pendingIdsWithFingerprint(string $integrationId, string $fingerprint): array
    {
        return array_column($this->fetchAll(
            'SELECT transaction_id FROM transactions
             WHERE integration_id = :integration_id AND fingerprint = :fingerprint AND pending = 1',
            ['integration_id' => $integrationId, 'fingerprint' => $fingerprint]
        ), 'transaction_id');
    }

    public function fingerprintOf(string $integrationId, string $transactionId): ?string
    {
        $row = $this->fetchRow(
            'SELECT fingerprint FROM transactions WHERE integration_id = :integration_id AND transaction_id = :transaction_id',
            ['integration_id' => $integrationId, 'transaction_id' => $transactionId]
        );

        return $row === null ? null : $row['fingerprint'];
    }

    public function save(string $integrationId, array $transaction, string $fingerprint, \DateTimeImmutable $now): void
    {
        $params = [
            'integration_id'   => $integrationId,
            'transaction_id'   => $transaction['transaction_id'],
            'account_id'       => $transaction['account_id'],
            'fingerprint'      => $fingerprint,
            'transaction_date' => $transaction['date'],
            'amount'           => round((float) $transaction['amount'], 2),
            'currency'         => $transaction['iso_currency_code'] ?? 'USD',
            'merchant'         => mb_substr(TransactionMapper::merchant($transaction), 0, 255),
            'name'             => mb_substr((string) ($transaction['name'] ?? ''), 0, 255),
            'category'         => TransactionMapper::category($transaction),
            'pending'          => ($transaction['pending'] ?? false) ? 1 : 0,
            'data'             => $this->encodeJson($transaction),
            'updated_at'       => Time::format($now),
        ];

        $updated = $this->execute(
            'UPDATE transactions SET account_id = :account_id, fingerprint = :fingerprint,
                transaction_date = :transaction_date, amount = :amount, currency = :currency,
                merchant = :merchant, name = :name, category = :category, pending = :pending,
                data = :data, updated_at = :updated_at
             WHERE integration_id = :integration_id AND transaction_id = :transaction_id',
            $params
        )->rowCount();

        if ($updated > 0 || $this->has($integrationId, $transaction['transaction_id'])) {
            return;
        }

        $params['created_at'] = Time::format($now);
        try {
            $this->execute(
                'INSERT INTO transactions (integration_id, transaction_id, account_id, fingerprint, transaction_date,
                    amount, currency, merchant, name, category, pending, data, created_at, updated_at)
                 VALUES (:integration_id, :transaction_id, :account_id, :fingerprint, :transaction_date,
                    :amount, :currency, :merchant, :name, :category, :pending, :data, :created_at, :updated_at)',
                $params
            );
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    public function delete(string $integrationId, string $transactionId): bool
    {
        return $this->execute(
            'DELETE FROM transactions WHERE integration_id = :integration_id AND transaction_id = :transaction_id',
            ['integration_id' => $integrationId, 'transaction_id' => $transactionId]
        )->rowCount() > 0;
    }

    public function since(string $integrationId, string $fromDate, int $limit = 5000): array
    {
        $limit = max(1, $limit);
        $rows = $this->fetchAll(
            "SELECT transaction_id, account_id, transaction_date, amount, currency, merchant, name, category
             FROM transactions
             WHERE integration_id = :integration_id AND transaction_date >= :from_date
             ORDER BY transaction_date DESC, id DESC
             LIMIT {$limit}",
            ['integration_id' => $integrationId, 'from_date' => $fromDate]
        );

        return array_map(static fn(array $row) => [
            'transaction_id' => $row['transaction_id'],
            'account_id'     => $row['account_id'],
            'date'           => $row['transaction_date'],
            'amount'         => (float) $row['amount'],
            'currency'       => $row['currency'],
            'merchant'       => $row['merchant'],
            'name'           => $row['name'],
            'category'       => $row['category'],
        ], $rows);
    }

    public function markFlagged(string $integrationId, string $transactionId, array $triggeredRules): void
    {
        $this->execute(
            'UPDATE transactions SET flagged = 1, triggered_rules = :triggered_rules
             WHERE integration_id = :integration_id AND transaction_id = :transaction_id',
            [
                'triggered_rules' => $this->encodeJson($triggeredRules),
                'integration_id'  => $integrationId,
                'transaction_id'  => $transactionId,
            ]
        );
    }
}
