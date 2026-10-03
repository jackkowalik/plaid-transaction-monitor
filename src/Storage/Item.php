<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * A linked Plaid item. One item can hold several bank accounts, and each
 * monitored account is an Integration.
 */
final class Item
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $accessToken,
        public readonly ?string $institutionName = null
    ) {
    }
}
