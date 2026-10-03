<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\MerchantHistoryStore;
use PlaidMonitor\Time\Time;
use Psr\Clock\ClockInterface;

/**
 * Flags merchants not seen in the lookback window ("new"), or seen on fewer
 * than maxPurchaseCount distinct days ("rare").
 *
 * Merchant history is recorded by the Processor for every new transaction,
 * whether or not this rule is enabled, so turning the rule on later works
 * from existing history.
 */
class NewMerchant implements RuleProcessor
{
    public function __construct(
        private readonly MerchantHistoryStore $history,
        private readonly ClockInterface $clock
    ) {
    }

    public function type(): string
    {
        return 'new_merchant';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $detectionType = (string) ($config['detectionType'] ?? 'new');
        $lookbackDays = max(1, (int) ($config['lookbackDays'] ?? 90));
        $maxPurchaseCount = max(1, (int) ($config['maxPurchaseCount'] ?? 3));
        $since = Time::date($this->clock->now()->modify("-{$lookbackDays} days"));

        $flags = [];
        $flaggedThisRun = [];

        foreach ($transactions as $transaction) {
            $merchant = TransactionMapper::merchant($transaction);
            if ($merchant === 'Unknown') {
                continue;
            }

            $daysSeen = $this->history->daysSeen($integration->integrationId, $merchant, $since);

            if ($detectionType === 'new') {
                $key = mb_strtolower($merchant);
                if ($daysSeen > 0 || isset($flaggedThisRun[$key])) {
                    continue;
                }
                $flaggedThisRun[$key] = true;

                $flags[] = new Flag(
                    $transaction,
                    sprintf('New merchant: %s (not seen in the last %d days)', $merchant, $lookbackDays),
                    Severity::Medium,
                    [
                        'merchant_name'  => $merchant,
                        'detection_type' => 'new',
                        'lookback_days'  => $lookbackDays,
                        'first_seen'     => $transaction['date'] ?? null,
                    ]
                );
            } elseif ($detectionType === 'rare' && $daysSeen < $maxPurchaseCount) {
                $flags[] = new Flag(
                    $transaction,
                    sprintf('Rare merchant: %s (seen on %d days in the last %d days)', $merchant, $daysSeen, $lookbackDays),
                    Severity::Low,
                    [
                        'merchant_name'  => $merchant,
                        'detection_type' => 'rare',
                        'days_seen'      => $daysSeen,
                        'lookback_days'  => $lookbackDays,
                    ]
                );
            }
        }

        return $flags;
    }
}
