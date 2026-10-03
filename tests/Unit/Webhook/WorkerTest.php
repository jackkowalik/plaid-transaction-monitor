<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoJobStore;
use PlaidMonitor\Storage\Pdo\PdoLockStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FrozenClock;
use PlaidMonitor\Webhook\ItemEventHandler;
use PlaidMonitor\Webhook\ItemLock;
use PlaidMonitor\Webhook\SyncHandler;
use PlaidMonitor\Webhook\Worker;
use Psr\Log\NullLogger;

class WorkerTest extends TestCase
{
    private PdoJobStore $jobs;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->jobs = new PdoJobStore(Database::sqlite());
        $this->clock = new FrozenClock('2026-10-01 12:00:00');
    }

    private function worker(SyncHandler $sync, ItemEventHandler $itemEvents, int $maxAttempts = 3): Worker
    {
        $logger = new NullLogger();
        return new Worker(
            $this->jobs,
            $sync,
            $itemEvents,
            new ItemLock(new PdoLockStore(Database::sqlite()), $this->clock, 300, $logger),
            $this->clock,
            $logger,
            $maxAttempts,
            360
        );
    }

    public function testDispatchesByWebhookType(): void
    {
        $sync = $this->createMock(SyncHandler::class);
        $sync->expects($this->once())->method('handle')->with('item_1');
        $itemEvents = $this->createMock(ItemEventHandler::class);
        $itemEvents->expects($this->once())->method('handle')->with('item_2', 'ERROR', ['item_id' => 'item_2']);

        $this->jobs->enqueue('item_1', 'TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE', ['item_id' => 'item_1'], $this->clock->now());
        $this->jobs->enqueue('item_2', 'ITEM', 'ERROR', ['item_id' => 'item_2'], $this->clock->now());

        $this->assertSame(2, $this->worker($sync, $itemEvents)->run());
        $this->assertNull($this->jobs->claim($this->clock->now()->modify('+1 day'), $this->clock->now()->modify('+2 days')));
    }

    public function testFailuresAreRetriedWithBackoffThenGivenUp(): void
    {
        $itemEvents = $this->createMock(ItemEventHandler::class);
        $itemEvents->method('handle')->willThrowException(new \RuntimeException('database down'));
        $worker = $this->worker($this->createMock(SyncHandler::class), $itemEvents, maxAttempts: 3);

        $this->jobs->enqueue('item_1', 'ITEM', 'ERROR', [], $this->clock->now());

        $this->assertTrue($worker->runOnce());
        $this->assertFalse($worker->runOnce(), 'not available again until the backoff has passed');

        $this->clock->advance('+30 seconds');
        $this->assertTrue($worker->runOnce());

        $this->clock->advance('+59 seconds');
        $this->assertFalse($worker->runOnce());
        $this->clock->advance('+1 second');
        $this->assertTrue($worker->runOnce());

        $this->clock->advance('+1 day');
        $this->assertFalse($worker->runOnce(), 'failed for good after the third attempt');
    }
}
