<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Processors\Velocity;
use PlaidMonitor\Storage\Pdo\PdoTransactionStore;
use PlaidMonitor\Tests\Support\Database;
use PlaidMonitor\Tests\Support\Fixtures;
use PlaidMonitor\Tests\Support\FrozenClock;

class VelocityTest extends TestCase
{
    private PdoTransactionStore $store;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->store = new PdoTransactionStore(Database::sqlite());
        $this->clock = new FrozenClock('2026-10-01 12:00:00');
    }

    private function seedBaseline(): void
    {
        // 30 USD a day for the 30 days before the window
        for ($day = 8; $day <= 30; $day++) {
            $date = $this->clock->now()->modify("-{$day} days")->format('Y-m-d');
            $tx = Fixtures::transaction(['date' => $date, 'amount' => 30.0]);
            $this->store->save('int_1', $tx, 'fp_' . $tx['transaction_id'], $this->clock->now());
        }
    }

    public function testFlagsSpendingAboveTheMultiplier(): void
    {
        $this->seedBaseline();

        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 2000.0, 'date' => '2026-10-01']),
        ], ['velocityType' => 'total', 'multiplier' => 3, 'timeWindowDays' => 7, 'baselineDays' => 30]);

        $this->assertCount(1, $flags);
        $this->assertGreaterThan(3, $flags[0]->details['ratio']);
    }

    public function testNormalSpendingIsNotFlagged(): void
    {
        $this->seedBaseline();

        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 30.0, 'date' => '2026-10-01']),
        ], ['velocityType' => 'total', 'multiplier' => 3]);

        $this->assertSame([], $flags);
    }

    public function testDepositsDoNotCountAsSpending(): void
    {
        $this->seedBaseline();

        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => -5000.0, 'date' => '2026-10-01', 'name' => 'Payroll']),
        ], ['velocityType' => 'total', 'multiplier' => 3]);

        $this->assertSame([], $flags);
    }

    public function testOnlyOutgoingTransactionsInTheWindowAreFlagged(): void
    {
        $this->seedBaseline();

        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['transaction_id' => 'spend', 'amount' => 2000.0, 'date' => '2026-10-01']),
            Fixtures::transaction(['transaction_id' => 'refund', 'amount' => -50.0, 'date' => '2026-10-01']),
            Fixtures::transaction(['transaction_id' => 'old', 'amount' => 10.0, 'date' => '2026-09-01']),
        ], ['velocityType' => 'total', 'multiplier' => 3, 'timeWindowDays' => 7]);

        $this->assertSame(['spend'], array_map(fn($f) => $f->transaction['transaction_id'], $flags));
    }

    public function testASpikeInsideTheWindowDoesNotRaiseItsOwnBaseline(): void
    {
        $this->seedBaseline();
        $spike = Fixtures::transaction(['amount' => 1500.0, 'date' => '2026-09-29']);
        $this->store->save('int_1', $spike, 'fp_spike', $this->clock->now());

        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 100.0, 'date' => '2026-10-01']),
        ], ['velocityType' => 'total', 'multiplier' => 3, 'timeWindowDays' => 7, 'baselineDays' => 30]);

        $this->assertCount(1, $flags);
        $this->assertSame(23.0, $flags[0]->details['baseline_value']);
    }

    public function testNoBaselineMeansNoFlags(): void
    {
        $flags = (new Velocity($this->store, $this->clock))->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 5000.0]),
        ], ['velocityType' => 'total']);

        $this->assertSame([], $flags);
    }
}
