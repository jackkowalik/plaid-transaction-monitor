<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

/**
 * XSalsa20-Poly1305 (libsodium secretbox) with a 32-byte key.
 *
 * Stored values look like "v1:<base64 nonce and ciphertext>". Values that
 * are still plain Plaid tokens ("access-...") are returned unchanged, so
 * existing rows keep working until they are saved again.
 */
class SodiumTokenCipher implements TokenCipher
{
    private const PREFIX = 'v1:';

    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('ACCESS_TOKEN_KEY requires the sodium extension');
        }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Access token key must be exactly 32 bytes');
        }
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(#[\SensitiveParameter] string $accessToken): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($accessToken, $nonce, $this->key));
    }

    public function decrypt(string $stored): string
    {
        if (str_starts_with($stored, 'access-')) {
            return $stored;
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Stored access token is in an unknown format');
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored access token is corrupt');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt the stored access token; check ACCESS_TOKEN_KEY');
        }

        return $plain;
    }
}
