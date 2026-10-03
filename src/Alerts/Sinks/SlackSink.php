<?php
declare(strict_types=1);

namespace PlaidMonitor\Alerts\Sinks;

use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Http\HttpException;
use Psr\Log\LoggerInterface;

/**
 * Posts a message to a Slack incoming webhook URL.
 */
class SlackSink
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(string $webhookUrl, string $text): bool
    {
        if (!str_starts_with($webhookUrl, 'https://hooks.slack.com/')) {
            $this->logger->warning('Slack webhook URL is not a hooks.slack.com URL; skipping');
            return false;
        }

        try {
            $response = $this->http->request(
                'POST',
                $webhookUrl,
                ['Content-Type' => 'application/json'],
                json_encode(['text' => $text], JSON_THROW_ON_ERROR),
                10
            );
        } catch (HttpException $e) {
            $this->logger->warning('Slack delivery failed', ['error' => $e->getMessage()]);
            return false;
        }

        if (!$response->isSuccess()) {
            $this->logger->warning('Slack rejected the message', ['status' => $response->status]);
            return false;
        }

        return true;
    }
}
