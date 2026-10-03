<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

/**
 * One transaction flagged by one rule processor.
 */
final class Flag
{
    /**
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly array $transaction,
        public readonly string $reason,
        public readonly Severity $severity,
        public readonly array $details = []
    ) {
    }
}
