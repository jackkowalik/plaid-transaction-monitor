<?php
declare(strict_types=1);

namespace PlaidMonitor\Scheduler;

use PlaidMonitor\EligibilityPolicy;
use PlaidMonitor\Plaid\PlaidClient;
use PlaidMonitor\Plaid\PlaidException;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\IntegrationStore;
use PlaidMonitor\Storage\LockStore;
use PlaidMonitor\Storage\RefreshRequestStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs from cron every 10 minutes and triggers paid refreshes for
 * short-interval integrations that are due. It does not process anything:
 * results arrive as SYNC_UPDATES_AVAILABLE webhooks.
 *
 * Every integration refreshed in one run gets the same
 * last_processing_started_at (the time the run began), so integrations at
 * the end of a long run do not drift a cycle behind.
 */
class Scheduler
{
    public function __construct(
        private readonly IntegrationStore $integrations,
        private readonly RefreshRequestStore $refreshRequests,
        private readonly LockStore $locks,
        private readonly PlaidClient $plaid,
        private readonly TimingPolicy $timing,
        private readonly EligibilityPolicy $eligibility,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Item errors (ITEM_LOGIN_REQUIRED and similar) are counted separately
     * from errors: they need the customer to repair the connection, and the
     * customer is told through the account.error alert, so they should not
     * make every cron run fail.
     *
     * @return array{due: int, items_refreshed: int, integrations_refreshed: int, item_errors: int, errors: int, expired_locks: int}
     */
    public function run(): array
    {
        $runStartedAt = $this->clock->now();
        $report = [
            'due'                    => 0,
            'items_refreshed'        => 0,
            'integrations_refreshed' => 0,
            'item_errors'            => 0,
            'errors'                 => 0,
            'expired_locks'          => $this->locks->deleteExpired($runStartedAt),
        ];

        if (!$this->timing->paidRefreshEnabled()) {
            $this->logger->info('Paid refresh is disabled; nothing to schedule', $report);
            return $report;
        }

        $due = array_filter(
            $this->integrations->findActiveWithIntervalBelow($this->timing->paidRefreshCutoffMinutes()),
            fn(Integration $i) => $this->timing->isDueForPaidRefresh($i, $runStartedAt) && $this->eligibility->isEligible($i)
        );
        $report['due'] = count($due);

        // One paid refresh per item covers every integration on it.
        $byItem = [];
        foreach ($due as $integration) {
            $byItem[$integration->itemId][] = $integration;
        }

        foreach ($byItem as $itemId => $itemIntegrations) {
            try {
                $item = $this->integrations->findItem((string) $itemId);
            } catch (\RuntimeException $e) {
                $this->logger->error('Could not load item', ['item_id' => $itemId, 'error' => $e->getMessage()]);
                $report['errors']++;
                continue;
            }
            if ($item === null) {
                $this->logger->error('Integration references a missing item', ['item_id' => $itemId]);
                $report['errors']++;
                continue;
            }

            // Replace any request left from a previous cycle (no webhook
            // means Plaid found nothing new), and record the new request
            // before calling Plaid so the webhook can never arrive first.
            $requestIds = [];
            foreach ($itemIntegrations as $integration) {
                $this->refreshRequests->deleteForIntegration($integration->integrationId);
                $requestIds[] = $this->refreshRequests->create($integration->integrationId, $item->itemId, $this->clock->now());
            }

            try {
                $this->plaid->refreshTransactions($item->accessToken);
            } catch (PlaidException $e) {
                foreach ($requestIds as $requestId) {
                    $this->refreshRequests->delete($requestId);
                }

                if ($e->errorType === 'ITEM_ERROR') {
                    // Back off for a full interval instead of retrying every run.
                    foreach ($itemIntegrations as $integration) {
                        $this->integrations->setProcessingStarted($integration->integrationId, $runStartedAt);
                    }
                    $this->logger->warning('Paid refresh skipped: the item needs attention', [
                        'item_id'    => $item->itemId,
                        'error_code' => $e->errorCode,
                    ]);
                    $report['item_errors']++;
                    continue;
                }

                $this->logger->error('Paid refresh failed', [
                    'item_id'    => $item->itemId,
                    'error_code' => $e->errorCode,
                    'error'      => $e->getMessage(),
                ]);
                $report['errors']++;
                continue;
            }

            foreach ($itemIntegrations as $integration) {
                $this->integrations->setProcessingStarted($integration->integrationId, $runStartedAt);
            }

            $report['items_refreshed']++;
            $report['integrations_refreshed'] += count($itemIntegrations);
        }

        $this->logger->info('Scheduler run complete', $report);

        return $report;
    }
}
