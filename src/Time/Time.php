<?php
declare(strict_types=1);

namespace PlaidMonitor\Time;

/**
 * All timestamps are computed in PHP and stored as UTC strings, so the
 * database clock and timezone never take part in a comparison.
 */
final class Time
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, $value, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            $parsed = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return $parsed;
    }

    public static function date(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    public static function secondsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
