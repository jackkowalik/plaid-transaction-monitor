<?php
declare(strict_types=1);

namespace PlaidMonitor\Webhook;

use PlaidMonitor\EligibilityPolicy;
use PlaidMonitor\Plaid\PlaidException;
use PlaidMonitor\Processor;
use PlaidMonitor\Scheduler\TimingPolicy;
use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Storage\IntegrationStore;
use PlaidMonitor\Storage\RefreshRequestStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles SYNC_UPDATES_AVAILABLE for one item, deciding per integration:
 *
 *   paid_refresh      a refresh we paid for is pending for this integration
 *   automatic_update  Plaid's own update, for integrations at or above the
 *                     paid cutoff, once enough of the interval has passed
 *   skip              anything else
 */
class SyncHandler
{
    public const SOURCE_PAID = 'paid_refresh';
    public const SOURCE_AUTOMATIC = 'automatic_update';

    public function __construct(
        private readonly IntegrationStore $integrations,
        private readonly RefreshRequestStore $refreshRequests,
        private readonly TimingPolicy $timing,
        private readonly EligibilityPolicy $eligibility,
        private readonly Processor $processor,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param (callable(): void)|null $heartbeat called before each integration to keep the item lock
     */
    public function handle(string $itemId, ?callable $heartbeat = null): void
    {
        $item = $this->integrations->findItem($itemId);
        if ($item === null) {
            $this->logger->warning('Sync webhook for an unknown item', ['item_id' => $itemId]);
            return;
        }

        foreach ($this->integrations->findActiveByItem($itemId) as $integration) {
            if (!$this->eligibility->isEligible($integration)) {
                continue;
            }

            if ($heartbeat !== null) {
                $heartbeat();
            }

            $now = $this->clock->now();
            $source = $this->decideSource($integration, $now);
            if ($source === null) {
                $this->logger->debug('Skipping integration for this update', ['integration_id' => $integration->integrationId]);
                continue;
            }

            if ($source === self::SOURCE_AUTOMATIC) {
                // The interval for automatic updates is measured from here.
                $this->integrations->setProcessingStarted($integration->integrationId, $now);
            }

            try {
                $this->processor->process($integration, $item, $source);
            } catch (\Throwable $e) {
                if (!$e instanceof PlaidException || $e->errorType !== 'ITEM_ERROR') {
                    if ($source === self::SOURCE_AUTOMATIC) {
                        // Let the retried job pass the timing check again.
                        $this->integrations->setProcessingStarted($integration->integrationId, $integration->lastProcessingStartedAt);
                    }
                    throw $e;
                }
                // Item errors (ITEM_LOGIN_REQUIRED and similar) will not fix
                // themselves on retry. Plaid also sends an ITEM ERROR webhook,
                // which is what notifies the customer.
                $this->logger->warning('Sync failed with an item error', [
                    'integration_id' => $integration->integrationId,
                    'error_code'     => $e->errorCode,
                ]);
                return;
            }
        }
    }

    public function decideSource(Integration $integration, \DateTimeImmutable $now): ?string
    {
        if ($this->refreshRequests->findPending($integration->integrationId) !== null) {
            return self::SOURCE_PAID;
        }

        if ($this->timing->acceptsAutomaticUpdate($integration, $now)) {
            return self::SOURCE_AUTOMATIC;
        }

        return null;
    }
}
