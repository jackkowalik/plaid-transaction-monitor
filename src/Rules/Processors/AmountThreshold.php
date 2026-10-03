<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Money;
use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;

/**
 * Flags outgoing transactions whose amount meets a condition. Plaid reports
 * money leaving the account as a positive amount; deposits are ignored.
 */
class AmountThreshold implements RuleProcessor
{
    public function type(): string
    {
        return 'amount_threshold';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $operator = (string) ($config['operator'] ?? 'greater_than');
        $threshold = (float) ($config['threshold'] ?? $config['amount'] ?? 1000);
        $currency = strtoupper((string) ($config['currency'] ?? 'USD'));
        $minAmount = isset($config['minAmount']) ? (float) $config['minAmount'] : null;
        $maxAmount = isset($config['maxAmount']) ? (float) $config['maxAmount'] : null;

        $flags = [];

        foreach ($transactions as $transaction) {
            $amount = (float) ($transaction['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            if (strtoupper((string) ($transaction['iso_currency_code'] ?? 'USD')) !== $currency) {
                continue;
            }

            $reason = match ($operator) {
                'greater_than' => $amount > $threshold
                    ? sprintf('Transaction amount %s exceeds threshold of %s', Money::format($amount, $currency), Money::format($threshold, $currency))
                    : null,
                'less_than' => $amount < $threshold
                    ? sprintf('Transaction amount %s is below threshold of %s', Money::format($amount, $currency), Money::format($threshold, $currency))
                    : null,
                'equals' => abs($amount - $threshold) < 0.01
                    ? sprintf('Transaction amount %s matches %s', Money::format($amount, $currency), Money::format($threshold, $currency))
                    : null,
                'between' => $minAmount !== null && $maxAmount !== null && $amount >= $minAmount && $amount <= $maxAmount
                    ? sprintf('Transaction amount %s is between %s and %s', Money::format($amount, $currency), Money::format($minAmount, $currency), Money::format($maxAmount, $currency))
                    : null,
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $flags[] = new Flag($transaction, $reason, $this->severity($amount, $threshold, $operator), [
                'amount'    => $amount,
                'threshold' => $threshold,
                'operator'  => $operator,
                'currency'  => $currency,
            ]);
        }

        return $flags;
    }

    private function severity(float $amount, float $threshold, string $operator): Severity
    {
        if ($operator !== 'greater_than' || $threshold <= 0) {
            return Severity::Medium;
        }

        $ratio = $amount / $threshold;

        return match (true) {
            $ratio >= 5 => Severity::Critical,
            $ratio >= 2 => Severity::High,
            $ratio >= 1.5 => Severity::Medium,
            default => Severity::Low,
        };
    }
}
