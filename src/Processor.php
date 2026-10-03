<?php
declare(strict_types=1);

namespace PlaidMonitor;

use PlaidMonitor\Alerts\AlertDispatcher;
use PlaidMonitor\Rules\EvaluationResult;
use PlaidMonitor\Rules\RuleEngine;
use PlaidMonitor\Rules\TriggeredRule;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\IntegrationStore;
use PlaidMonitor\Storage\Item;
use PlaidMonitor\Storage\RefreshRequestStore;
use PlaidMonitor\Storage\TransactionStore;
use PlaidMonitor\Sync\Deduplicator;
use PlaidMonitor\Sync\TransactionSyncer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes one integration after a SYNC_UPDATES_AVAILABLE webhook:
 * sync from the saved cursor, drop transactions already processed, run the
 * rules, store the alert, store the transactions and the new cursor, and
 * finally deliver the alert.
 *
 * Transactions and the cursor are saved only after the rules have run, so a
 * crash mid-run leaves the transactions unprocessed and the next run
 * evaluates them again. Delivery comes last because webhook retries can take
 * up to 30 seconds and the alert record already exists by then.
 *
 * Until Plaid reports that the item's historical pull is complete, synced
 * transactions are stored without being evaluated. That history becomes the
 * baseline for the velocity and new_merchant rules instead of producing an
 * alert for every past transaction. Plaid can take a while to pull history
 * for a new item, so this can span several syncs.
 */
class Processor
{
    public function __construct(
        private readonly IntegrationStore $integrations,
        private readonly RefreshRequestStore $refreshRequests,
        private readonly TransactionStore $transactions,
        private readonly TransactionSyncer $syncer,
        private readonly Deduplicator $deduplicator,
        private readonly RuleEngine $rules,
        private readonly AlertDispatcher $alerts,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $source 'paid_refresh' or 'automatic_update'
     */
    public function process(Integration $integration, Item $item, string $source): EvaluationResult
    {
        $inBaseline = !$integration->baselineComplete;
        $startedAt = $this->clock->now();

        $sync =$this->syncer->syncAll($item->accessToken, $integration->cursor);
        $batch = $this->deduplicator->classify($integration, $sync);

        $result = $inBaseline
            ? new EvaluationResult([], 0)
            : $this->rules->evaluate($integration, $batch->toEvaluate());

        $alert = $result->hasFlags()
            ? $this->alerts->recordFraudAlert($integration, $item, $result, $source)
            : null;

        $now = $this->clock->now();
        $this->deduplicator->persist($integration, $batch, $now);

        foreach ($result->flagged as $flagged) {
            $this->transactions->markFlagged(
                $integration->integrationId,
                (string) $flagged->transaction['transaction_id'],
                array_map(static fn(TriggeredRule $rule) => $rule->toArray(), $flagged->rules())
            );
        }

        $this->integrations->recordProcessed(
            $integration->integrationId,
            $sync->nextCursor !== '' ? $sync->nextCursor : (string) $integration->cursor,
            $source,
            $inBaseline ? 0 : count($batch->toEvaluate()),
            $alert !== null ? 1 : 0,
            $now,
            $integration->baselineComplete || $sync->historyComplete()
        );

        // A refresh requested before this sync started has been answered by
        // it. One the scheduler created since then is still waiting.
        $this->refreshRequests->deleteForIntegration($integration->integrationId, $startedAt);

        $this->logger->info('Integration processed', [
            'integration_id'    => $integration->integrationId,
            'source'            => $source,
            'baseline'          => $inBaseline,
            'update_status'     => $sync->updateStatus,
            'pages'             => $sync->pages,
            'new'               => count($batch->new),
            'modified'          => count($batch->modified),
            'removed'           => count($batch->removed),
            'already_processed' => $batch->alreadyProcessed,
            'flagged'           => count($result->flagged),
            'alert_id'          => $alert?->alertId,
        ]);

        if ($alert !== null) {
            $this->alerts->deliverFraudAlert($integration, $item, $alert, $result);
        }

        return $result;
    }
}
