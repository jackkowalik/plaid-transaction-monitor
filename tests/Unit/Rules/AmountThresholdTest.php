<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Processors\AmountThreshold;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Tests\Support\Fixtures;

class AmountThresholdTest extends TestCase
{
    public function testFlagsAmountsOverTheThreshold(): void
    {
        $flags = (new AmountThreshold())->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 1200.0]),
            Fixtures::transaction(['amount' => 900.0]),
        ], ['operator' => 'greater_than', 'threshold' => 1000]);

        $this->assertCount(1, $flags);
        $this->assertSame(1200.0, $flags[0]->transaction['amount']);
        $this->assertSame(Severity::Low, $flags[0]->severity);
    }

    public function testSeverityScalesWithTheRatio(): void
    {
        $flags = (new AmountThreshold())->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 5000.0]),
            Fixtures::transaction(['amount' => 2000.0]),
        ], ['operator' => 'greater_than', 'threshold' => 1000]);

        $this->assertSame(Severity::Critical, $flags[0]->severity);
        $this->assertSame(Severity::High, $flags[1]->severity);
    }

    public function testIgnoresDepositsAndOtherCurrencies(): void
    {
        $flags = (new AmountThreshold())->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => -5000.0]),
            Fixtures::transaction(['amount' => 5000.0, 'iso_currency_code' => 'EUR']),
        ], ['operator' => 'greater_than', 'threshold' => 1000, 'currency' => 'USD']);

        $this->assertSame([], $flags);
    }

    public function testBetween(): void
    {
        $flags = (new AmountThreshold())->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 50.0]),
            Fixtures::transaction(['amount' => 150.0]),
        ], ['operator' => 'between', 'minAmount' => 100, 'maxAmount' => 200]);

        $this->assertCount(1, $flags);
        $this->assertSame(150.0, $flags[0]->transaction['amount']);
    }

    public function testZeroThresholdDoesNotDivideByZero(): void
    {
        $flags = (new AmountThreshold())->evaluate(Fixtures::integration(), [
            Fixtures::transaction(['amount' => 10.0]),
        ], ['operator' => 'greater_than', 'threshold' => 0]);

        $this->assertSame(Severity::Medium, $flags[0]->severity);
    }
}
