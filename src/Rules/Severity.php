<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    /**
     * Highest severity in the list, or Low for an empty list.
     */
    public static function highest(Severity ...$severities): self
    {
        $highest = self::Low;
        foreach ($severities as $severity) {
            if ($severity->rank() > $highest->rank()) {
                $highest = $severity;
            }
        }
        return $highest;
    }
}
