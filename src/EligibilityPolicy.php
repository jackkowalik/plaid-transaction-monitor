<?php
declare(strict_types=1);

namespace PlaidMonitor;

use PlaidMonitor\Storage\Integration;

/**
 * Hook for your own account checks, such as billing or plan limits.
 * Ineligible integrations are skipped by the scheduler and the webhook path.
 */
interface EligibilityPolicy
{
    public function isEligible(Integration $integration): bool;
}
