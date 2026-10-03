<?php
declare(strict_types=1);

namespace PlaidMonitor\Cache;

use Psr\SimpleCache\InvalidArgumentException;

class InvalidCacheKey extends \InvalidArgumentException implements InvalidArgumentException
{
}
