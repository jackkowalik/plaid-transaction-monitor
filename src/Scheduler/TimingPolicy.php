<?php
declare(strict_types=1);

namespace PlaidMonitor\Scheduler;

use PlaidMonitor\Storage\Integration;
use PlaidMonitor\Time\Time;

/**
 * Decides which path an integration uses and whether it is due.
 *
 * Thresholds are fractions of each integration's own interval, so any
 * interval in minutes works. Elapsed time is measured from when processing
 * last started, not when it finished, so integrations processed late in a
 * long run are not pushed back a whole cycle.
 */
final class TimingPolicy
{
    public function __construct(
        private readonly bool $paidRefreshEnabled,
        private readonly int $paidRefreshCutoffMinutes,
        private readonly float $shortThreshold,
        private readonly float $autoThreshold
    ) {
    }

    public function paidRefreshEnabled(): bool
    {
        return $this->paidRefreshEnabled;
    }

    public function paidRefreshCutoffMinutes(): int
    {
        return $this->paidRefreshCutoffMinutes;
    }

    /**
     * Short intervals are driven by the scheduler's paid refreshes; everything
     * else waits for Plaid's automatic updates.
     */
    public function usesPaidRefresh(Integration $integration): bool
    {
        return $this->paidRefreshEnabled && $integration->intervalMinutes < $this->paidRefreshCutoffMinutes;
    }

    public function isDueForPaidRefresh(Integration $integration, \DateTimeImmutable $now): bool
    {
        return $this->usesPaidRefresh($integration)
            && $this->hasElapsed($integration->lastProcessingStartedAt, $integration->intervalMinutes, $this->shortThreshold, $now);
    }

    /**
     * Whether an automatic Plaid update should be processed for this integration.
     */
    public function acceptsAutomaticUpdate(Integration $integration, \DateTimeImmutable $now): bool
    {
        return !$this->usesPaidRefresh($integration)
            && $this->hasElapsed($integration->lastProcessingStartedAt, $integration->intervalMinutes, $this->autoThreshold, $now);
    }

    public function hasElapsed(?\DateTimeImmutable $lastStartedAt, int $intervalMinutes, float $fraction, \DateTimeImmutable $now): bool
    {
        if ($lastStartedAt === null) {
            return true;
        }

        $requiredSeconds = (int) floor($intervalMinutes * 60 * $fraction);

        return Time::secondsBetween($lastStartedAt, $now) >= $requiredSeconds;
    }
}
