<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage\Pdo;

use PlaidMonitor\Storage\AlertStore;
use PlaidMonitor\Storage\Ids;
use PlaidMonitor\Time\Time;

class PdoAlertStore extends PdoStore implements AlertStore
{
    public function create(
        string $kind,
        string $itemId,
        ?string $integrationId,
        string $severity,
        array $payload,
        \DateTimeImmutable $createdAt
    ): string {
        $alertId = Ids::generate('alert');

        $this->execute(
            'INSERT INTO alerts (alert_id, kind, item_id, integration_id, severity, payload, created_at)
             VALUES (:alert_id, :kind, :item_id, :integration_id, :severity, :payload, :created_at)',
            [
                'alert_id'       => $alertId,
                'kind'           => $kind,
                'item_id'        => $itemId,
                'integration_id' => $integrationId,
                'severity'       => $severity,
                'payload'        => $this->encodeJson($payload),
                'created_at'     => Time::format($createdAt),
            ]
        );

        return $alertId;
    }
}
