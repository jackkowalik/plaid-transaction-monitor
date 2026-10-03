<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts\Sinks;

use PlaidMonitor\Alerts\WebhookSigner;
use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Http\HttpException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends signed webhook events. Failed deliveries are retried after each of
 * the configured delays (5, 10 and 15 seconds by default).
 */
class WebhookSink
{
    private const TIMEOUT_SECONDS = 5;

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param list<int> $retryDelays seconds to wait before each retry
     * @param (callable(int): void)|null $sleep
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly array $retryDelays = [5, 10, 15],
        ?callable $sleep = null
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(string $url, string $secret, string $event, array $data): bool
    {
        $timestamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $body = json_encode(
            ['event' => $event, 'timestamp' => $timestamp, 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $headers = [
            'Content-Type'        => 'application/json',
            'X-Webhook-Signature' => WebhookSigner::sign($timestamp, $body, $secret),
        ];
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown host';

        $attempts = array_merge([0], $this->retryDelays);

        foreach ($attempts as $attempt => $delay) {
            if ($delay > 0) {
                ($this->sleep)($delay);
            }

            try {
                $response = $this->http->request('POST', $url, $headers, $body, self::TIMEOUT_SECONDS);
                if ($response->isSuccess()) {
                    $this->logger->info('Webhook delivered', ['event' => $event, 'host' => $host, 'attempt' => $attempt + 1]);
                    return true;
                }
                $this->logger->warning('Webhook rejected', [
                    'event'   => $event,
                    'host'    => $host,
                    'attempt' => $attempt + 1,
                    'status'  => $response->status,
                ]);
            } catch (HttpException $e) {
                $this->logger->warning('Webhook delivery failed', [
                    'event'   => $event,
                    'host'    => $host,
                    'attempt' => $attempt + 1,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->error('Webhook delivery failed after all retries', ['event' => $event, 'host' => $host]);
        return false;
    }
}
