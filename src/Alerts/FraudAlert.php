<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts;

final class FraudAlert
{
    /**
     * @param array<string, mixed> $payload the "data" object of the fraud_alert.detected event
     */
    public function __construct(
        public readonly string $alertId,
        public readonly array $payload
    ) {
    }
}
