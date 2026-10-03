<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoJobStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FrozenClock;
use PlaidMonitor\Webhook\WebhookReceiver;
use Psr\Log\NullLogger;

class WebhookReceiverTest extends TestCase
{
    private PdoJobStore $jobs;
    private FrozenClock $clock;
    private WebhookReceiver $receiver;

    protected function setUp(): void
    {
        $this->jobs = new PdoJobStore(Database::sqlite());
        $this->clock = new FrozenClock();
        $this->receiver = new WebhookReceiver(null, $this->jobs, $this->clock, new NullLogger());
    }

    private function claim(): ?\PlaidMonitor\Storage\Job
    {
        return $this->jobs->claim($this->clock->now(), $this->clock->now()->modify('+5 minutes'));
    }

    public function testSyncUpdatesAreQueued(): void
    {
        $response = $this->receiver->handle(
            '{"webhook_type":"TRANSACTIONS","webhook_code":"SYNC_UPDATES_AVAILABLE","item_id":"item_1"}',
            []
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame('queued', $response['body']['status']);
        $this->assertSame('item_1', $this->claim()?->itemId);
    }

    public function testItemEventsAreQueued(): void
    {
        $this->receiver->handle('{"webhook_type":"ITEM","webhook_code":"PENDING_EXPIRATION","item_id":"item_1"}', []);

        $this->assertSame('PENDING_EXPIRATION', $this->claim()?->webhookCode);
    }

    public function testOtherWebhooksAreAcknowledgedButNotQueued(): void
    {
        $response = $this->receiver->handle('{"webhook_type":"TRANSACTIONS","webhook_code":"RECURRING_TRANSACTIONS_UPDATE","item_id":"item_1"}', []);

        $this->assertSame(200, $response['status']);
        $this->assertNull($this->claim());
    }

    public function testMalformedPayloadsAreRejected(): void
    {
        $this->assertSame(400, $this->receiver->handle('not json', [])['status']);
        $this->assertSame(400, $this->receiver->handle('{"webhook_type":"ITEM","webhook_code":"ERROR"}', [])['status']);
    }
}
