<?php
declare(strict_types=1);

namespace PlaidMonitor\Webhook;

use PlaidMonitor\Plaid\WebhookVerifier;
use PlaidMonitor\Storage\JobStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Receives Plaid webhooks: verifies the signature, queues the event, and
 * answers right away. bin/worker.php does the processing.
 */
class WebhookReceiver
{
    private const HANDLED = [
        'TRANSACTIONS' => ['SYNC_UPDATES_AVAILABLE'],
        'ITEM'         => [
            'ERROR',
            'PENDING_EXPIRATION',
            'PENDING_DISCONNECT',
            'USER_PERMISSION_REVOKED',
            'USER_ACCOUNT_REVOKED',
        ],
    ];

    /**
     * @param WebhookVerifier|null $verifier null disables verification (local testing only)
     */
    public function __construct(
        private readonly ?WebhookVerifier $verifier,
        private readonly JobStore $jobs,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $rawBody, array $headers): array
    {
        if ($this->verifier !== null && !$this->verifier->verify($rawBody, $headers)) {
            return ['status' => 401, 'body' => ['error' => 'invalid signature']];
        }

        $payload = json_decode($rawBody, true);
        $type = is_array($payload) ? ($payload['webhook_type'] ?? null) : null;
        $code = is_array($payload) ? ($payload['webhook_code'] ?? null) : null;
        $itemId = is_array($payload) ? ($payload['item_id'] ?? null) : null;

        if (!is_string($type) || !is_string($code) || !is_string($itemId) || $itemId === '') {
            return ['status' => 400, 'body' => ['error' => 'expected webhook_type, webhook_code and item_id']];
        }

        if (!in_array($code, self::HANDLED[$type] ?? [], true)) {
            $this->logger->debug('Ignoring webhook', ['webhook_type' => $type, 'webhook_code' => $code]);
            return ['status' => 200, 'body' => ['status' => 'ignored']];
        }

        $this->jobs->enqueue($itemId, $type, $code, $payload, $this->clock->now());
        $this->logger->info('Webhook queued', ['webhook_type' => $type, 'webhook_code' => $code, 'item_id' => $itemId]);

        return ['status' => 200, 'body' => ['status' => 'queued']];
    }
}
