<?php
declare(strict_types=1);

namespace PlaidMonitor;

use PlaidMonitor\Storage\Integration;

class AlwaysEligible implements EligibilityPolicy
{
    public function isEligible(Integration $integration): bool
    {
        return true;
    }
}
