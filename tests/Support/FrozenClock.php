<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Support;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-01 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(string $now): void
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }
}
