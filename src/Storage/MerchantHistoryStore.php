<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

interface MerchantHistoryStore
{
    /**
     * Number of distinct days on or after $sinceDate on which the merchant was seen.
     */
    public function daysSeen(string $integrationId, string $merchant, string $sinceDate): int;

    public function record(string $integrationId, string $merchant, string $date): void;
}
