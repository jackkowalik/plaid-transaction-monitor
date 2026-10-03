<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Processors;

use PlaidMonitor\Money;
use PlaidMonitor\Plaid\TransactionMapper;
use PlaidMonitor\Rules\Flag;
use PlaidMonitor\Rules\Geocoding\Geocoder;
use PlaidMonitor\Rules\RuleProcessor;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Storage\Integration;
use Psr\Log\LoggerInterface;

/**
 * Flags transactions located outside every allowed zone.
 *
 * Zones:
 *   {"type": "circle", "center": {"lat": .., "lng": ..}, "radius": meters}
 *   {"type": "polygon", "coordinates": [[lat, lng], ...]}   at least 3 vertices
 *   {"type": "rectangle", "coordinates": [[lat, lng], ...]} two opposite corners, or all four
 *
 * Transactions with coordinates are checked directly. Transactions with only
 * an address are geocoded when a Geocoder is configured, and skipped otherwise.
 */
class Geolocation implements RuleProcessor
{
    private const EARTH_RADIUS_METERS = 6371000;

    public function __construct(
        private readonly ?Geocoder $geocoder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function type(): string
    {
        return 'geolocation';
    }

    public function evaluate(Integration $integration, array $transactions, array $config): array
    {
        $zones = array_values((array) ($config['allowedZones'] ?? []));
        $includeOnline = (bool) ($config['includeOnlineTransactions'] ?? false);

        if ($zones === []) {
            return [];
        }

        $errors = $this->validateZones($zones);
        if ($errors !== []) {
            $this->logger->warning('Geolocation rule has invalid zones', [
                'integration_id' => $integration->integrationId,
                'errors'         => $errors,
            ]);
            return [];
        }

        $flags = [];

        foreach ($transactions as $transaction) {
            $channel = $this->paymentChannel($transaction);
            if ($channel === 'online' && !$includeOnline) {
                continue;
            }

            $location = $transaction['location'] ?? null;
            if (!is_array($location)) {
                continue;
            }

            $point = $this->coordinates($location);
            if ($point === null || $this->insideAnyZone($point, $zones)) {
                continue;
            }

            $merchant = TransactionMapper::merchant($transaction);
            $amount = abs((float) ($transaction['amount'] ?? 0));
            $description = $this->describe($location, $merchant);
            $distanceKm = $this->distanceToNearestZoneKm($point, $zones);

            $flags[] = new Flag(
                $transaction,
                sprintf(
                    'Transaction at %s is outside all allowed zones: %s (%s)',
                    $merchant,
                    $description,
                    Money::format($amount, (string) ($transaction['iso_currency_code'] ?? 'USD'))
                ),
                $this->severity($channel, $distanceKm),
                [
                    'merchant_name'            => $merchant,
                    'payment_channel'          => $channel,
                    'amount'                   => $amount,
                    'transaction_location'     => $point,
                    'location_description'     => $description,
                    'allowed_zones_count'      => count($zones),
                    'distance_to_nearest_zone' => $distanceKm,
                ]
            );
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $location
     * @return array{lat: float, lng: float}|null
     */
    private function coordinates(array $location): ?array
    {
        if (isset($location['lat'], $location['lon']) && is_numeric($location['lat']) && is_numeric($location['lon'])) {
            $lat = (float) $location['lat'];
            $lng = (float) $location['lon'];
            return $this->isValidCoordinate($lat, $lng) ? ['lat' => $lat, 'lng' => $lng] : null;
        }

        if ($this->geocoder === null) {
            return null;
        }

        $parts = array_filter([
            $location['address'] ?? null,
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['postal_code'] ?? null,
            $location['country'] ?? null,
        ], static fn($part) => $part !== null && $part !== '');

        if ($parts === []) {
            return null;
        }

        return $this->geocoder->geocode(implode(', ', $parts));
    }

    /**
     * @param list<mixed> $zones
     * @return list<string>
     */
    private function validateZones(array $zones): array
    {
        $errors = [];

        foreach ($zones as $index => $zone) {
            $name = is_array($zone) && isset($zone['name']) ? (string) $zone['name'] : "zone {$index}";
            $type = is_array($zone) ? ($zone['type'] ?? null) : null;

            switch ($type) {
                case 'circle':
                    if (!isset($zone['center']['lat'], $zone['center']['lng'])
                        || !is_numeric($zone['center']['lat']) || !is_numeric($zone['center']['lng'])
                        || !$this->isValidCoordinate((float) $zone['center']['lat'], (float) $zone['center']['lng'])) {
                        $errors[] = "{$name}: circle needs a valid center";
                    }
                    if (!isset($zone['radius']) || !is_numeric($zone['radius']) || (float) $zone['radius'] <= 0) {
                        $errors[] = "{$name}: circle needs a positive radius in meters";
                    }
                    break;

                case 'polygon':
                case 'rectangle':
                    $minimum = $type === 'polygon' ? 3 : 2;
                    $coordinates = $zone['coordinates'] ?? null;
                    if (!is_array($coordinates) || count($coordinates) < $minimum) {
                        $errors[] = "{$name}: {$type} needs at least {$minimum} coordinates";
                        break;
                    }
                    foreach ($coordinates as $i => $vertex) {
                        if (!isset($vertex[0], $vertex[1]) || !is_numeric($vertex[0]) || !is_numeric($vertex[1])
                            || !$this->isValidCoordinate((float) $vertex[0], (float) $vertex[1])) {
                            $errors[] = "{$name}: vertex {$i} is not a valid [lat, lng] pair";
                        }
                    }
                    break;

                default:
                    $errors[] = "{$name}: unknown zone type";
            }
        }

        return $errors;
    }

    private function isValidCoordinate(float $lat, float $lng): bool
    {
        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function paymentChannel(array $transaction): string
    {
        return match ($transaction['payment_channel'] ?? null) {
            'in store' => 'in_store',
            'online' => 'online',
            'other' => 'other',
            default => match ($transaction['transaction_type'] ?? null) {
                'place' => 'in_store',
                'digital' => 'online',
                'special' => 'other',
                default => 'unknown',
            },
        };
    }

    /**
     * @param array{lat: float, lng: float} $point
     * @param list<array<string, mixed>> $zones
     */
    private function insideAnyZone(array $point, array $zones): bool
    {
        foreach ($zones as $zone) {
            $inside = match ($zone['type']) {
                'circle' => $this->haversine($point['lat'], $point['lng'], (float) $zone['center']['lat'], (float) $zone['center']['lng'])
                    <= (float) $zone['radius'],
                'polygon' => $this->insidePolygon($point, $zone['coordinates']),
                'rectangle' => $this->insideRectangle($point, $zone['coordinates']),
                default => false,
            };
            if ($inside) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ray casting on [lat, lng] vertices.
     *
     * @param array{lat: float, lng: float} $point
     * @param list<array{0: float, 1: float}> $vertices
     */
    private function insidePolygon(array $point, array $vertices): bool
    {
        $inside = false;
        $count = count($vertices);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = (float) $vertices[$i][0];
            $lngI = (float) $vertices[$i][1];
            $latJ = (float) $vertices[$j][0];
            $lngJ = (float) $vertices[$j][1];

            if (($lngI > $point['lng']) !== ($lngJ > $point['lng'])
                && $point['lat'] < ($latJ - $latI) * ($point['lng'] - $lngI) / ($lngJ - $lngI) + $latI) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * @param array{lat: float, lng: float} $point
     * @param list<array{0: float, 1: float}> $corners
     */
    private function insideRectangle(array $point, array $corners): bool
    {
        $lats = array_map(static fn($c) => (float) $c[0], $corners);
        $lngs = array_map(static fn($c) => (float) $c[1], $corners);

        return $point['lat'] >= min($lats) && $point['lat'] <= max($lats)
            && $point['lng'] >= min($lngs) && $point['lng'] <= max($lngs);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Distance to the nearest circle edge, or the nearest vertex for
     * polygons and rectangles.
     *
     * @param array{lat: float, lng: float} $point
     * @param list<array<string, mixed>> $zones
     */
    private function distanceToNearestZoneKm(array $point, array $zones): float
    {
        $nearest = INF;

        foreach ($zones as $zone) {
            if ($zone['type'] === 'circle') {
                $distance = max(0.0, $this->haversine($point['lat'], $point['lng'], (float) $zone['center']['lat'], (float) $zone['center']['lng'])
                    - (float) $zone['radius']);
            } else {
                $distance = INF;
                foreach ($zone['coordinates'] as $vertex) {
                    $distance = min($distance, $this->haversine($point['lat'], $point['lng'], (float) $vertex[0], (float) $vertex[1]));
                }
            }
            $nearest = min($nearest, $distance);
        }

        return round($nearest / 1000, 2);
    }

    /**
     * @param array<string, mixed> $location
     */
    private function describe(array $location, string $merchant): string
    {
        $parts = array_filter([
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['country'] ?? null,
        ], static fn($part) => $part !== null && $part !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        if (isset($location['lat'], $location['lon'])) {
            return sprintf('%.4f, %.4f', $location['lat'], $location['lon']);
        }

        return "near {$merchant}";
    }

    private function severity(string $channel, float $distanceKm): Severity
    {
        return match ($channel) {
            'online' => match (true) {
                $distanceKm > 5000 => Severity::High,
                $distanceKm > 1000 => Severity::Medium,
                default => Severity::Low,
            },
            'in_store' => match (true) {
                $distanceKm > 1000 => Severity::High,
                $distanceKm > 100 => Severity::Medium,
                default => Severity::Low,
            },
            default => Severity::Medium,
        };
    }
}
