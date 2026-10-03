<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * Queue of received webhooks. The receiver only enqueues, so Plaid gets its
 * response immediately; bin/worker.php does the processing.
 */
interface JobStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $itemId, string $webhookType, string $webhookCode, array $payload, \DateTimeImmutable $now): void;

    /**
     * Claim the oldest available job, or a job whose previous claim has expired.
     * Each claim increments the job's attempt count.
     */
    public function claim(\DateTimeImmutable $now, \DateTimeImmutable $claimUntil): ?Job;

    public function complete(int $jobId): void;

    /**
     * Record a failure. With $retryAt the job becomes available again at
     * that time, otherwise it is marked failed for good.
     */
    public function fail(int $jobId, string $error, ?\DateTimeImmutable $retryAt): void;
}
