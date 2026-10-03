<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Storage\MerchantHistoryStore;

class PdoMerchantHistoryStore extends PdoStore implements MerchantHistoryStore
{
    public function daysSeen(string $integrationId, string $merchant, string $sinceDate): int
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS days FROM merchant_history
             WHERE integration_id = :integration_id AND merchant = :merchant AND seen_date >= :since_date',
            ['integration_id' => $integrationId, 'merchant' => $this->normalize($merchant), 'since_date' => $sinceDate]
        );

        return (int) ($row['days'] ?? 0);
    }

    public function record(string $integrationId, string $merchant, string $date): void
    {
        try {
            $this->execute(
                'INSERT INTO merchant_history (integration_id, merchant, seen_date)
                 VALUES (:integration_id, :merchant, :seen_date)',
                ['integration_id' => $integrationId, 'merchant' => $this->normalize($merchant), 'seen_date' => $date]
            );
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * Lowercased so matching behaves the same under MySQL's case-insensitive
     * collation and SQLite's case-sensitive comparison.
     */
    private function normalize(string $merchant): string
    {
        return mb_substr(mb_strtolower(trim($merchant)), 0, 255);
    }
}
