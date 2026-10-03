<?php
declare(strict_types=1);

namespace PlaidMonitor;

final class Money
{
    public static function format(float $amount, string $currency): string
    {
        return strtoupper($currency) . ' ' . number_format($amount, 2);
    }
}
