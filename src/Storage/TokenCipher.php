<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * Encrypts Plaid access tokens before they are stored.
 */
interface TokenCipher
{
    public function encrypt(string $accessToken): string;

    public function decrypt(string $stored): string;
}
