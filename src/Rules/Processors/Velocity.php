<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\TransactionStore;
use PlaidMonitor\Time\Time;
use Psr\Clock\ClockInterface;

/**
 * Compares recent spending with a baseline. Only outgoing transactions count
 * as spending.
 *
 * The window is the last timeWindowDays days. The baseline is the
 * baselineDays days before the window, so a spike inside the window does not
 * raise its own baseline.
 *
 * velocityType:
 *   total   - daily spend over the window vs daily spend over the baseline
 *   count   - daily transaction count over the window vs the baseline
 *   average - average transaction size over the window vs the baseline
 *
 * When the window value exceeds baseline * multiplier, the outgoing
 * transactions from this run that fall inside the window are flagged.
 */
class Velocity implements RuleProcessor
{
    public function __construct(
        private readonly TransactionStore $transactions,
        private readonly ClockInterface $clock
    ) {
    }

    public function type(): string
    {
        return 'velocity';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $multiplier = (float) ($config['multiplier'] ?? 3);
        $windowDays = max(1, (int) ($config['timeWindowDays'] ?? 7));
        $baselineDays = max(1, (int) ($config['baselineDays'] ?? 30));
        $velocityType = (string) ($config['velocityType'] ?? 'total');

        if ($transactions === [] || !in_array($velocityType, ['total', 'count', 'average'], true)) {
            return [];
        }

        $now = $this->clock->now();
        $windowStart = Time::date($now->modify("-{$windowDays} days"));
        $baselineStart = Time::date($now->modify('-' . ($windowDays + $baselineDays) . ' days'));

        $toFlag = array_values(array_filter(
            $transactions,
            static fn(array $t) => (float) ($t['amount'] ?? 0) > 0 && ($t['date'] ?? '') >= $windowStart
        ));
        if ($toFlag === []) {
            return [];
        }

        // Stored history, minus anything in this run (modified transactions
        // are already stored and would otherwise be counted twice).
        $runIds = array_flip(array_map(static fn(array $t) => (string) $t['transaction_id'], $transactions));
        $history = array_values(array_filter(
            $this->transactions->since($integration->integrationId, $baselineStart),
            static fn(array $row) => !isset($runIds[$row['transaction_id']]) && $row['amount'] > 0
        ));

        $baselineAmounts = array_column(
            array_filter($history, static fn(array $row) => $row['date'] < $windowStart),
            'amount'
        );

        $baseline = $this->value($baselineAmounts, $baselineDays, $velocityType);
        if ($baseline === null || $baseline <= 0) {
            return [];
        }

        $windowAmounts = array_column(
            array_filter($history, static fn(array $row) => $row['date'] >= $windowStart),
            'amount'
        );
        foreach ($toFlag as $transaction) {
            $windowAmounts[] = (float) $transaction['amount'];
        }

        $recent = $this->value($windowAmounts, $windowDays, $velocityType) ?? 0.0;
        if ($recent <= $baseline * $multiplier) {
            return [];
        }

        $ratio = $recent / $baseline;
        $severity = match (true) {
            $ratio >= 10 => Severity::Critical,
            $ratio >= 5 => Severity::High,
            $ratio >= 3 => Severity::Medium,
            default => Severity::Low,
        };
        $reason = sprintf(
            'Spending velocity %.1fx the baseline (%.2f vs %.2f, %s over %d days)',
            $ratio,
            $recent,
            $baseline,
            $velocityType,
            $windowDays
        );
        $details = [
            'velocity_type'    => $velocityType,
            'baseline_value'   => round($baseline, 2),
            'recent_value'     => round($recent, 2),
            'ratio'            => round($ratio, 2),
            'time_window_days' => $windowDays,
            'baseline_days'    => $baselineDays,
        ];

        return array_map(static fn(array $t) => new Flag($t, $reason, $severity, $details), $toFlag);
    }

    /**
     * @param list<float> $amounts
     */
    private function value(array $amounts, int $days, string $velocityType): ?float
    {
        if ($amounts === []) {
            return null;
        }

        return match ($velocityType) {
            'total' => array_sum($amounts) / $days,
            'count' => count($amounts) / $days,
            'average' => array_sum($amounts) / count($amounts),
        };
    }
}
