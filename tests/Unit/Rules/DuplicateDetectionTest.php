<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Processors\DuplicateDetection;
use PlaidMonitor\Storage\Pdo\PdoTransactionStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;

class DuplicateDetectionTest extends TestCase
{
    private PdoTransactionStore $store;
    private DuplicateDetection $rule;

    protected function setUp(): void
    {
        $this->store = new PdoTransactionStore(Database::sqlite());
        $clock = new FrozenClock('2026-10-01 12:00:00');
        $this->rule = new DuplicateDetection($this->store, $clock);
    }

    public function testMatchesAStoredTransaction(): void
    {
        $earlier = Fixtures::transaction(['amount' => 49.99, 'merchant_name' => 'Streaming Co', 'date' => '2026-09-30']);
        $this->store->save('int_1', $earlier, 'fp_a', new \DateTimeImmutable());

        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 49.99, 'merchant_name' => 'Streaming Co']),
        ], ['timeWindowHours' => 72]);

        $this->assertCount(1, $flags);
        $this->assertSame(1, $flags[0]->details['duplicate_count']);
    }

    public function testATransactionNeverMatchesItsOwnStoredCopy(): void
    {
        $tx = Fixtures::transaction(['amount' => 49.99, 'merchant_name' => 'Streaming Co']);
        $this->store->save('int_1', $tx, 'fp_a', new \DateTimeImmutable());

        $this->assertSame([], $this->rule->evaluate(Fixtures::integration(), [$tx], []));
    }

    public function testTwoIdenticalTransactionsInOneRunFlagEachOther(): void
    {
        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 20.0, 'merchant_name' => 'Taxi']),
            Fixtures::transaction(['amount' => 20.0, 'merchant_name' => 'Taxi']),
        ], []);

        $this->assertCount(2, $flags);
    }

    public function testARefundIsNotADuplicateOfThePurchase(): void
    {
        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 80.0, 'merchant_name' => 'Shoe Store']),
            Fixtures::transaction(['amount' => -80.0, 'merchant_name' => 'Shoe Store']),
        ], []);

        $this->assertSame([], $flags);
    }

    public function testDifferentMerchantsDoNotMatch(): void
    {
        $flags = $this->rule->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 20.0, 'merchant_name' => 'Taxi']),
            Fixtures::transaction(['amount' => 20.0, 'merchant_name' => 'Bakery']),
        ], ['checkMerchant' => true]);

        $this->assertSame([], $flags);
    }
}
