<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoLockStore;
use PlaidMonitor\Tests\Support\Database;

class PdoLockStoreTest extends TestCase
{
    private PdoLockStore $locks;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->locks = new PdoLockStore(Database::sqlite());
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->now->modify($modifier);
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $this->assertTrue($this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes')));
        $this->assertFalse($this->locks->tryAcquire('item', 'b', $this->now, $this->at('+5 minutes')));
    }

    public function testExpiredLockCanBeTakenOverOnce(): void
    {
        $this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes'));
        $later = $this->at('+6 minutes');

        $this->assertTrue($this->locks->tryAcquire('item', 'b', $later, $later->modify('+5 minutes')));
        $this->assertFalse($this->locks->tryAcquire('item', 'c', $later, $later->modify('+5 minutes')));
    }

    public function testReleaseOnlyWorksForTheOwner(): void
    {
        $this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes'));

        $this->locks->forceRelease('item', 'b');
        $this->assertFalse($this->locks->tryAcquire('item', 'b', $this->now, $this->at('+5 minutes')));

        $this->assertTrue($this->locks->release('item', 'a'));
        $this->assertTrue($this->locks->tryAcquire('item', 'b', $this->now, $this->at('+5 minutes')));
    }

    public function testPendingRerunBlocksRelease(): void
    {
        $this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes'));

        $this->assertTrue($this->locks->requestRerun('item', $this->now));
        $this->assertFalse($this->locks->release('item', 'a'));

        $this->assertTrue($this->locks->consumeRerun('item', 'a'));
        $this->assertFalse($this->locks->consumeRerun('item', 'a'));
        $this->assertTrue($this->locks->release('item', 'a'));
    }

    public function testRerunNeedsALiveLock(): void
    {
        $this->assertFalse($this->locks->requestRerun('item', $this->now));

        $this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes'));
        $this->assertFalse($this->locks->requestRerun('item', $this->at('+10 minutes')));
    }

    public function testExtendingTwiceInTheSameSecondStillReportsOwnership(): void
    {
        $this->locks->tryAcquire('item', 'a', $this->now, $this->at('+5 minutes'));

        $this->assertTrue($this->locks->extend('item', 'a', $this->at('+6 minutes')));
        $this->assertTrue($this->locks->extend('item', 'a', $this->at('+6 minutes')));
        $this->assertFalse($this->locks->extend('item', 'b', $this->at('+6 minutes')));
    }

    public function testDeleteExpired(): void
    {
        $this->locks->tryAcquire('old', 'a', $this->now, $this->at('+1 minute'));
        $this->locks->tryAcquire('new', 'b', $this->now, $this->at('+10 minutes'));

        $this->assertSame(1, $this->locks->deleteExpired($this->at('+5 minutes')));
    }
}
