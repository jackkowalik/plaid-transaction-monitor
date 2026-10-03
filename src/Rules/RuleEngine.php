<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

use PlaidMonitor\Storage\Integration;
use Psr\Log\LoggerInterface;

class RuleEngine
{
    /** @var array<string, RuleProcessor> */
    private array $processors = [];

    /**
     * @param iterable<RuleProcessor> $processors
     */
    public function __construct(iterable $processors, private readonly LoggerInterface $logger)
    {
        foreach ($processors as $processor) {
            $this->processors[$processor->type()] = $processor;
        }
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->processors);
    }

    /**
     * Run every enabled rule on the integration against the transactions.
     * A rule that throws is logged and skipped; the others still run.
     *
     * @param list<array<string, mixed>> $transactions
     */
    public function evaluate(Integration $integration, array $transactions): EvaluationResult
    {
        if ($transactions === []) {
            return new EvaluationResult([], 0);
        }

        /** @var array<string, FlaggedTransaction> $flagged */
        $flagged = [];

        foreach ($integration->enabledRules() as $index => $rule) {
            $ruleId = isset($rule['id']) ? (string) $rule['id'] : null;
            $ruleType = isset($rule['type']) ? (string) $rule['type'] : null;

            if ($ruleId === null || $ruleType === null) {
                $this->logger->warning('Skipping rule without id or type', [
                    'integration_id' => $integration->integrationId,
                    'rule_index'     => $index,
                ]);
                continue;
            }

            $processor = $this->processors[$ruleType] ?? null;
            if ($processor === null) {
                $this->logger->warning('Skipping rule with unknown type', [
                    'integration_id' => $integration->integrationId,
                    'rule_id'        => $ruleId,
                    'rule_type'      => $ruleType,
                ]);
                continue;
            }

            try {
                $flags = $processor->evaluate($integration, $transactions, (array) ($rule['config'] ?? []));
            } catch (\Throwable $e) {
                $this->logger->error('Rule processor failed', [
                    'integration_id' => $integration->integrationId,
                    'rule_id'        => $ruleId,
                    'rule_type'      => $ruleType,
                    'error'          => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($flags as $flag) {
                $transactionId = (string) $flag->transaction['transaction_id'];
                $flagged[$transactionId] ??= new FlaggedTransaction($flag->transaction);
                $flagged[$transactionId]->add(new TriggeredRule(
                    ruleId: $ruleId,
                    ruleType: $ruleType,
                    ruleName: (string) ($rule['name'] ?? $ruleType),
                    reason: $flag->reason,
                    severity: $flag->severity,
                    details: $flag->details,
                    emailAlert: ($rule['emailAlert'] ?? false) === true,
                    slackAlert: ($rule['slackAlert'] ?? false) === true,
                ));
            }
        }

        $this->logger->info('Rules evaluated', [
            'integration_id'       => $integration->integrationId,
            'transactions_checked' => count($transactions),
            'flagged'              => count($flagged),
        ]);

        return new EvaluationResult(array_values($flagged), count($transactions));
    }
}
