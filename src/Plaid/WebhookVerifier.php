<?php
declare(strict_types=1);

namespace PlaidMonitor\Plaid;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Verifies the Plaid-Verification JWT that Plaid attaches to every webhook:
 * ES256 signature by a key from /webhook_verification_key/get, issued within
 * the last five minutes, and a request_body_sha256 claim that matches the body.
 */
class WebhookVerifier
{
    private const MAX_AGE_SECONDS = 300;
    private const KEY_CACHE_TTL = 86400;

    public function __construct(
        private readonly PlaidClient $plaid,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function verify(string $rawBody, array $headers): bool
    {
        $token = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'plaid-verification') {
                $token = $value;
                break;
            }
        }

        if ($token === null || $token === '') {
            $this->logger->warning('Webhook rejected: missing Plaid-Verification header');
            return false;
        }

        $header = $this->decodeHeader($token);
        if ($header === null || ($header['alg'] ?? null) !== 'ES256' || empty($header['kid'])) {
            $this->logger->warning('Webhook rejected: malformed JWT header');
            return false;
        }

        $jwk = $this->verificationKey((string) $header['kid']);
        if ($jwk === null) {
            return false;
        }

        try {
            $now = $this->clock->now()->getTimestamp();
            JWT::$timestamp = $now;
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => [$jwk]], 'ES256'));
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook rejected: JWT verification failed', ['error' => $e->getMessage()]);
            return false;
        } finally {
            JWT::$timestamp = null;
        }

        if (!isset($claims->iat) || $now - (int) $claims->iat > self::MAX_AGE_SECONDS) {
            $this->logger->warning('Webhook rejected: JWT too old or missing iat');
            return false;
        }

        if (!isset($claims->request_body_sha256)
            || !hash_equals((string) $claims->request_body_sha256, hash('sha256', $rawBody))) {
            $this->logger->warning('Webhook rejected: body hash mismatch');
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeHeader(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verificationKey(string $keyId): ?array
    {
        $cacheKey = 'plaid_jwk_' . preg_replace('/[^A-Za-z0-9_.]/', '_', $keyId);

        $key = $this->cache->get($cacheKey);
        if (!is_array($key)) {
            try {
                $key = $this->plaid->getWebhookVerificationKey($keyId);
            } catch (PlaidException $e) {
                $this->logger->error('Could not fetch webhook verification key', ['error' => $e->getMessage()]);
                return null;
            }
            $this->cache->set($cacheKey, $key, self::KEY_CACHE_TTL);
        }

        if (!empty($key['expired_at'])) {
            $this->logger->warning('Webhook rejected: verification key has expired', ['key_id' => $keyId]);
            return null;
        }

        $key['alg'] ??= 'ES256';

        return $key;
    }
}
