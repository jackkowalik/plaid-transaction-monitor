<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Scheduler\TimingPolicy;
use PlaidMonitor\Tests\Support\Fixtures;

class TimingPolicyTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testShortIntervalsUsePaidRefreshOnlyWhenEnabled(): void
    {
        $integration = Fixtures::integration(intervalMinutes: 60);

        $this->assertTrue((new TimingPolicy(true, 1440, 0.9, 0.85))->usesPaidRefresh($integration));
        $this->assertFalse((new TimingPolicy(false, 1440, 0.9, 0.85))->usesPaidRefresh($integration));
    }

    public function testIntervalAtCutoffUsesAutomaticUpdates(): void
    {
        $policy = new TimingPolicy(true, 1440, 0.9, 0.85);

        $this->assertFalse($policy->usesPaidRefresh(Fixtures::integration(intervalMinutes: 1440)));
        $this->assertTrue($policy->usesPaidRefresh(Fixtures::integration(intervalMinutes: 1439)));
    }

    public function testThresholdIsComputedFromTheIntervalInMinutes(): void
    {
        $policy = new TimingPolicy(true, 1440, 0.9, 0.85);

        // 45 minutes * 0.9 = 40.5 minutes
        $this->assertFalse($policy->isDueForPaidRefresh(Fixtures::integration(45, lastStartedAt: '2026-10-01 11:20:00'), $this->now()));
        $this->assertTrue($policy->isDueForPaidRefresh(Fixtures::integration(45, lastStartedAt: '2026-10-01 11:19:30'), $this->now()));
    }

    public function testNeverProcessedIsDue(): void
    {
        $policy = new TimingPolicy(true, 1440, 0.9, 0.85);

        $this->assertTrue($policy->isDueForPaidRefresh(Fixtures::integration(30), $this->now()));
        $this->assertTrue($policy->acceptsAutomaticUpdate(Fixtures::integration(2880), $this->now()));
    }

    public function testAutomaticUpdateRespectsLongIntervals(): void
    {
        $policy = new TimingPolicy(true, 1440, 0.9, 0.85);
        $twoDays = 2880;

        // 2880 * 0.85 = 2448 minutes = 40.8 hours
        $this->assertFalse($policy->acceptsAutomaticUpdate(Fixtures::integration($twoDays, lastStartedAt: '2026-09-29 20:00:00'), $this->now()));
        $this->assertTrue($policy->acceptsAutomaticUpdate(Fixtures::integration($twoDays, lastStartedAt: '2026-09-29 19:00:00'), $this->now()));
    }

    public function testPaidIntegrationsNeverAcceptAutomaticUpdates(): void
    {
        $policy = new TimingPolicy(true, 1440, 0.9, 0.85);

        $this->assertFalse($policy->acceptsAutomaticUpdate(Fixtures::integration(60, lastStartedAt: '2026-09-01 00:00:00'), $this->now()));
    }

    public function testWithPaidRefreshDisabledShortIntervalsTakeEveryAutomaticUpdate(): void
    {
        $policy = new TimingPolicy(false, 1440, 0.9, 0.85);

        $this->assertTrue($policy->acceptsAutomaticUpdate(Fixtures::integration(30, lastStartedAt: '2026-10-01 11:30:00'), $this->now()));
        $this->assertFalse($policy->isDueForPaidRefresh(Fixtures::integration(30), $this->now()));
    }
}
