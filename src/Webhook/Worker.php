<?php
declare(strict_types=1);

namespace PlaidMonitor\Webhook;

use PlaidMonitor\Storage\Job;
use PlaidMonitor\Storage\JobStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes queued webhooks. A failed job is retried with exponential
 * backoff (30s, 60s, 120s, ...) until it has been attempted maxAttempts times.
 */
class Worker
{
    private const BASE_BACKOFF_SECONDS = 30;

    public function __construct(
        private readonly JobStore $jobs,
        private readonly SyncHandler $sync,
        private readonly ItemEventHandler $itemEvents,
        private readonly ItemLock $itemLock,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts,
        private readonly int $claimSeconds
    ) {
    }

    /**
     * Process jobs until the queue is empty or $maxJobs have been processed.
     *
     * @return int number of jobs processed
     */
    public function run(int $maxJobs = 0): int
    {
        $processed = 0;
        while (($maxJobs === 0 || $processed < $maxJobs) && $this->runOnce()) {
            $processed++;
        }
        return $processed;
    }

    /**
     * @return bool false when there was nothing to do
     */
    public function runOnce(): bool
    {
        $now = $this->clock->now();
        $job = $this->jobs->claim($now, $now->modify("+{$this->claimSeconds} seconds"));
        if ($job === null) {
            return false;
        }

        try {
            $this->dispatch($job);
            $this->jobs->complete($job->id);
        } catch (\Throwable $e) {
            $retryAt = $job->attempts < $this->maxAttempts
                ? $this->clock->now()->modify('+' . (self::BASE_BACKOFF_SECONDS * 2 ** ($job->attempts - 1)) . ' seconds')
                : null;

            $this->jobs->fail($job->id, $e->getMessage(), $retryAt);
            $this->logger->error('Webhook job failed', [
                'job_id'       => $job->id,
                'item_id'      => $job->itemId,
                'webhook_code' => $job->webhookCode,
                'attempt'      => $job->attempts,
                'will_retry'   => $retryAt !== null,
                'error'        => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function dispatch(Job $job): void
    {
        if ($job->webhookType === 'TRANSACTIONS' && $job->webhookCode === 'SYNC_UPDATES_AVAILABLE') {
            $this->itemLock->run($job->itemId, fn(callable $heartbeat) => $this->sync->handle($job->itemId, $heartbeat));
            return;
        }

        if ($job->webhookType === 'ITEM') {
            $this->itemEvents->handle($job->itemId, $job->webhookCode, $job->payload);
            return;
        }

        $this->logger->warning('Dropping job with an unrecognized webhook', [
            'job_id'       => $job->id,
            'webhook_type' => $job->webhookType,
            'webhook_code' => $job->webhookCode,
        ]);
    }
}
