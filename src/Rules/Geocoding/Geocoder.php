<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Geocoding;

interface Geocoder
{
    /**
     * @return array{lat: float, lng: float}|null null when the address could not be resolved
     */
    public function geocode(string $address): ?array;
}
