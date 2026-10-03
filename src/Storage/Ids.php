<?php
declare(strict_types=1);

namespace PlaidMonitor\Storage;

final class Ids
{
    public static function generate(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
