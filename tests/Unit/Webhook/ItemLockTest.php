<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Storage\Pdo\PdoLockStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\FrozenClock;
use PlaidMonitor\Webhook\ItemLock;
use Psr\Log\NullLogger;

class ItemLockTest extends TestCase
{
    private PdoLockStore $locks;
    private FrozenClock $clock;
    private ItemLock $lock;

    protected function setUp(): void
    {
        $this->locks = new PdoLockStore(Database::sqlite());
        $this->clock = new FrozenClock();
        $this->lock = new ItemLock($this->locks, $this->clock, 300, new NullLogger());
    }

    public function testRunsTheWorkAndReleases(): void
    {
        $runs = 0;

        $this->assertTrue($this->lock->run('item', function () use (&$runs) { $runs++; }));
        $this->assertSame(1, $runs);
        $this->assertTrue($this->locks->tryAcquire('item', 'x', $this->clock->now(), $this->clock->now()->modify('+1 minute')));
    }

    public function testAWebhookArrivingMidRunCausesAnotherPass(): void
    {
        $runs = 0;
        $lock = $this->lock;

        $this->lock->run('item', function () use (&$runs, $lock) {
            $runs++;
            if ($runs === 1) {
                // A second worker gets the same item while we are working.
                $handled = $lock->run('item', function () {
                    throw new \LogicException('second worker must not run the work itself');
                });
                $this->assertFalse($handled);
            }
        });

        $this->assertSame(2, $runs);
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        try {
            $this->lock->run('item', function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->locks->tryAcquire('item', 'x', $this->clock->now(), $this->clock->now()->modify('+1 minute')));
    }

    public function testRerunsAreCapped(): void
    {
        $runs = 0;
        $locks = $this->locks;
        $clock = $this->clock;

        $this->lock->run('item', function () use (&$runs, $locks, $clock) {
            $runs++;
            $locks->requestRerun('item', $clock->now());
        });

        $this->assertSame(5, $runs);
    }
}
