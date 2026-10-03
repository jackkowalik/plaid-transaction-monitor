<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Severity;

class SeverityTest extends TestCase
{
    public function testCriticalOutranksHigh(): void
    {
        $this->assertSame(Severity::Critical, Severity::highest(Severity::High, Severity::Critical, Severity::Low));
    }

    public function testEmptyListIsLow(): void
    {
        $this->assertSame(Severity::Low, Severity::highest());
    }
}
