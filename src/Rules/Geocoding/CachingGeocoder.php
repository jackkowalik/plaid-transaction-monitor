<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Geocoding;

use Psr\SimpleCache\CacheInterface;

/**
 * Caches results in memory for the current run and in a PSR-16 cache across
 * runs. Misses are cached too, for a shorter time, so a bad address is not
 * looked up on every run.
 */
class CachingGeocoder implements Geocoder
{
    private const HIT_TTL = 2592000;
    private const MISS_TTL = 86400;

    /** @var array<string, array{lat: float, lng: float}|false> */
    private array $memory = [];

    public function __construct(
        private readonly Geocoder $inner,
        private readonly CacheInterface $cache
    ) {
    }

    public function geocode(string $address): ?array
    {
        $key = 'geocode_' . md5(strtolower(trim($address)));

        if (!array_key_exists($key, $this->memory)) {
            $cached = $this->cache->get($key);

            if (is_array($cached) || $cached === false) {
                $this->memory[$key] = $cached;
            } else {
                $result = $this->inner->geocode($address);
                $this->memory[$key] = $result ?? false;
                $this->cache->set($key, $this->memory[$key], $result !== null ? self::HIT_TTL : self::MISS_TTL);
            }
        }

        return $this->memory[$key] === false ? null : $this->memory[$key];
    }
}
