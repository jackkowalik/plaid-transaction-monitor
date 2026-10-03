<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Money;
use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\TransactionStore;
use PlaidMonitor\Time\Time;
use Psr\Clock\ClockInterface;

/**
 * Flags a transaction when another transaction within the time window has an
 * amount within amountThreshold percent and, optionally, the same merchant
 * and a similar description. Candidates are stored transactions plus the
 * rest of this run.
 */
class DuplicateDetection implements RuleProcessor
{
    public function __construct(
        private readonly TransactionStore $transactions,
        private readonly ClockInterface $clock
    ) {
    }

    public function type(): string
    {
        return 'duplicate_detection';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $windowHours = max(1, (int) ($config['timeWindowHours'] ?? 72));
        $amountThresholdPercent = (float) ($config['amountThreshold'] ?? 1.0);
        $checkMerchant = (bool) ($config['checkMerchant'] ?? true);
        $checkDescription = (bool) ($config['checkDescription'] ?? false);

        if ($transactions === []) {
            return [];
        }

        $windowStart = Time::date($this->clock->now()->modify("-{$windowHours} hours"));

        $runIds = array_flip(array_map(static fn(array $t) => (string) $t['transaction_id'], $transactions));
        $stored = array_filter(
            $this->transactions->since($integration->integrationId, $windowStart),
            static fn(array $row) => !isset($runIds[$row['transaction_id']])
        );

        $candidates = [];
        foreach ($stored as $row) {
            $candidates[] = [
                'transaction_id' => $row['transaction_id'],
                'date'           => $row['date'],
                'amount'         => $row['amount'],
                'merchant'       => $row['merchant'],
                'name'           => $row['name'],
            ];
        }
        foreach ($transactions as $transaction) {
            $candidates[] = [
                'transaction_id' => (string) $transaction['transaction_id'],
                'date'           => $transaction['date'] ?? '',
                'amount'         => (float) ($transaction['amount'] ?? 0),
                'merchant'       => TransactionMapper::merchant($transaction),
                'name'           => (string) ($transaction['name'] ?? ''),
            ];
        }

        $flags = [];

        foreach ($transactions as $transaction) {
            $duplicates = $this->findDuplicates($transaction, $candidates, $amountThresholdPercent, $checkMerchant, $checkDescription);
            if ($duplicates === []) {
                continue;
            }

            $merchant = TransactionMapper::merchant($transaction);
            $amount = abs((float) ($transaction['amount'] ?? 0));
            $count = count($duplicates);

            $flags[] = new Flag(
                $transaction,
                sprintf(
                    'Possible duplicate: %s at %s on %s matches %d other %s',
                    Money::format($amount, (string) ($transaction['iso_currency_code'] ?? 'USD')),
                    $merchant,
                    $transaction['date'] ?? 'unknown date',
                    $count,
                    $count === 1 ? 'transaction' : 'transactions'
                ),
                $this->severity($count, $amount),
                [
                    'merchant_name'       => $merchant,
                    'amount'              => $amount,
                    'transaction_date'    => $transaction['date'] ?? null,
                    'duplicate_count'     => $count,
                    'duplicates'          => array_map(static fn(array $d) => [
                        'transaction_id' => $d['transaction_id'],
                        'date'           => $d['date'],
                        'amount'         => abs((float) $d['amount']),
                        'merchant'       => $d['merchant'],
                    ], $duplicates),
                    'time_window_hours'   => $windowHours,
                    'matched_merchant'    => $checkMerchant,
                    'matched_description' => $checkDescription,
                ]
            );
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param list<array{transaction_id: string, date: string, amount: float, merchant: string, name: string}> $candidates
     * @return list<array{transaction_id: string, date: string, amount: float, merchant: string, name: string}>
     */
    private function findDuplicates(
        array $transaction,
        array $candidates,
        float $amountThresholdPercent,
        bool $checkMerchant,
        bool $checkDescription
    ): array {
        $transactionId = (string) $transaction['transaction_id'];
        $signedAmount = (float) ($transaction['amount'] ?? 0);
        $amount = abs($signedAmount);
        if ($amount == 0.0) {
            return [];
        }

        $merchant = $this->normalizeMerchant(TransactionMapper::merchant($transaction));
        $description = $this->normalizeDescription((string) ($transaction['name'] ?? ''));

        $duplicates = [];

        foreach ($candidates as $candidate) {
            if ($candidate['transaction_id'] === $transactionId) {
                continue;
            }

            // A refund is not a duplicate of the purchase it refunds.
            if (($signedAmount > 0) !== ((float) $candidate['amount'] > 0)) {
                continue;
            }

            $candidateAmount = abs((float) $candidate['amount']);
            if ($candidateAmount == 0.0) {
                continue;
            }

            if (abs($amount - $candidateAmount) / $amount * 100 > $amountThresholdPercent) {
                continue;
            }

            if ($checkMerchant) {
                $candidateMerchant = $this->normalizeMerchant($candidate['merchant']);
                if ($merchant === '' || $merchant === 'unknown' || $merchant !== $candidateMerchant) {
                    continue;
                }
            }

            if ($checkDescription && $this->similarity($description, $this->normalizeDescription($candidate['name'])) < 0.8) {
                continue;
            }

            $duplicates[] = $candidate;
        }

        return $duplicates;
    }

    private function normalizeMerchant(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
        return preg_replace('/[^a-z0-9\s]/', '', $normalized) ?? '';
    }

    private function normalizeDescription(string $description): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($description))) ?? '';
        $normalized = preg_replace('/\d{4,}/', '', $normalized) ?? '';
        return preg_replace('/[^a-z0-9\s]/', '', $normalized) ?? '';
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100.0;
    }

    private function severity(int $duplicateCount, float $amount): Severity
    {
        return match (true) {
            $duplicateCount >= 5 || $amount >= 5000 => Severity::Critical,
            $duplicateCount >= 3 || $amount >= 1000 => Severity::High,
            $duplicateCount >= 2 || $amount >= 500 => Severity::Medium,
            default => Severity::Low,
        };
    }
}
