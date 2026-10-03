<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts;

/**
 * X-Webhook-Signature is the hex HMAC-SHA256 of "{timestamp}.{raw body}",
 * where timestamp is the payload's top-level "timestamp" field.
 */
final class WebhookSigner
{
    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    public static function verify(string $rawBody, string $signature, string $secret): bool
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['timestamp'])) {
            return false;
        }

        return hash_equals(self::sign((string) $payload['timestamp'], $rawBody, $secret), $signature);
    }
}
