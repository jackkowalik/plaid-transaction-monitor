<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * Stores access tokens as given. Used when ACCESS_TOKEN_KEY is not set.
 */
class PlainTokenCipher implements TokenCipher
{
    public function encrypt(string $accessToken): string
    {
        return $accessToken;
    }

    public function decrypt(string $stored): string
    {
        return $stored;
    }
}
