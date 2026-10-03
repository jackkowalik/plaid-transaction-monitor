<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoJobStore;
use PlaidMonitor\Tests\Support\Database;

class PdoJobStoreTest extends TestCase
{
    private PdoJobStore $jobs;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->jobs = new PdoJobStore(Database::sqlite());
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testClaimsInOrderAndNeverTwice(): void
    {
        $this->jobs->enqueue('item_a', 'TRANSACTIONS', 'SYNC_UPDATES_AVAILABLE', ['item_id' => 'item_a'], $this->now);
        $this->jobs->enqueue('item_b', 'ITEM', 'ERROR', ['item_id' => 'item_b'], $this->now);

        $first = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));
        $second = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));

        $this->assertSame('item_a', $first?->itemId);
        $this->assertSame('item_b', $second?->itemId);
        $this->assertSame(['item_id' => 'item_b'], $second->payload);
        $this->assertSame(1, $second->attempts);
        $this->assertNull($this->jobs->claim($this->now, $this->now->modify('+5 minutes')));
    }

    public function testCompletedJobsAreGone(): void
    {
        $this->jobs->enqueue('item', 'ITEM', 'ERROR', [], $this->now);
        $job = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));
        $this->jobs->complete($job->id);

        $this->assertNull($this->jobs->claim($this->now->modify('+1 hour'), $this->now->modify('+2 hours')));
    }

    public function testFailedJobsComeBackAtTheRetryTime(): void
    {
        $this->jobs->enqueue('item', 'ITEM', 'ERROR', [], $this->now);
        $job = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));
        $this->jobs->fail($job->id, 'boom', $this->now->modify('+30 seconds'));

        $this->assertNull($this->jobs->claim($this->now->modify('+10 seconds'), $this->now->modify('+5 minutes')));
        $retry = $this->jobs->claim($this->now->modify('+31 seconds'), $this->now->modify('+5 minutes'));
        $this->assertSame(2, $retry?->attempts);
    }

    public function testPermanentlyFailedJobsAreNotClaimed(): void
    {
        $this->jobs->enqueue('item', 'ITEM', 'ERROR', [], $this->now);
        $job = $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));
        $this->jobs->fail($job->id, 'boom', null);

        $this->assertNull($this->jobs->claim($this->now->modify('+1 day'), $this->now->modify('+2 days')));
    }

    public function testAbandonedClaimsCanBeReclaimed(): void
    {
        $this->jobs->enqueue('item', 'ITEM', 'ERROR', [], $this->now);
        $this->jobs->claim($this->now, $this->now->modify('+5 minutes'));

        $this->assertNull($this->jobs->claim($this->now->modify('+1 minute'), $this->now->modify('+6 minutes')));
        $this->assertNotNull($this->jobs->claim($this->now->modify('+6 minutes'), $this->now->modify('+11 minutes')));
    }
}
