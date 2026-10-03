<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Rules\Geocoding\Geocoder;
use PlaidMonitor\Rules\Processors\Geolocation;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Tests\Support\Fixtures;
use Psr\Log\NullLogger;

class GeolocationTest extends TestCase
{
    private const NYC_CIRCLE = [
        'type'   => 'circle',
        'name'   => 'New York',
        'center' => ['lat' => 40.7128, 'lng' => -74.0060],
        'radius' => 50000,
    ];

    /**
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    private function at(array $location, string $channel = 'in store'): array
    {
        return Fixtures::transaction([
            'payment_channel' => $channel,
            'location'        => array_merge(
                ['address' => null, 'city' => null, 'region' => null, 'postal_code' => null, 'country' => null, 'lat' => null, 'lon' => null],
                $location
            ),
        ]);
    }

    public function testTransactionInsideACircleIsNotFlagged(): void
    {
        $rule = new Geolocation(null, new NullLogger());

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['lat' => 40.73, 'lon' => -73.99]),
        ], ['allowedZones' => [self::NYC_CIRCLE]]);

        $this->assertSame([], $flags);
    }

    public function testTransactionFarAwayIsFlaggedHigh(): void
    {
        $rule = new Geolocation(null, new NullLogger());

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['lat' => 34.05, 'lon' => -118.24, 'city' => 'Los Angeles', 'region' => 'CA']),
        ], ['allowedZones' => [self::NYC_CIRCLE]]);

        $this->assertCount(1, $flags);
        $this->assertSame(Severity::High, $flags[0]->severity);
        $this->assertStringContainsString('Los Angeles', $flags[0]->reason);
    }

    public function testPolygonZone(): void
    {
        $rule = new Geolocation(null, new NullLogger());
        $square = ['type' => 'polygon', 'coordinates' => [[40, -75], [41, -75], [41, -73], [40, -73]]];

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['lat' => 40.5, 'lon' => -74.0]),
            $this->at(['lat' => 42.5, 'lon' => -74.0]),
        ], ['allowedZones' => [$square]]);

        $this->assertCount(1, $flags);
        $this->assertSame(42.5, $flags[0]->details['transaction_location']['lat']);
    }

    public function testRectangleFromTwoCorners(): void
    {
        $rule = new Geolocation(null, new NullLogger());
        $box = ['type' => 'rectangle', 'coordinates' => [[40, -75], [41, -73]]];

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['lat' => 40.5, 'lon' => -74.0]),
            $this->at(['lat' => 45.0, 'lon' => -74.0]),
        ], ['allowedZones' => [$box]]);

        $this->assertCount(1, $flags);
        $this->assertSame(45.0, $flags[0]->details['transaction_location']['lat']);
    }

    public function testOnlineTransactionsAreSkippedUnlessIncluded(): void
    {
        $rule = new Geolocation(null, new NullLogger());
        $tx = $this->at(['lat' => 34.05, 'lon' => -118.24], 'online');

        $this->assertSame([], $rule->evaluate(Fixtures::integration(), [$tx], ['allowedZones' => [self::NYC_CIRCLE]]));
        $this->assertCount(1, $rule->evaluate(Fixtures::integration(), [$tx], [
            'allowedZones'              => [self::NYC_CIRCLE],
            'includeOnlineTransactions' => true,
        ]));
    }

    public function testAddressOnlyTransactionsAreGeocoded(): void
    {
        $geocoder = new class implements Geocoder {
            public array $calls = [];
            public function geocode(string $address): ?array
            {
                $this->calls[] = $address;
                return ['lat' => 51.5074, 'lng' => -0.1278];
            }
        };
        $rule = new Geolocation($geocoder, new NullLogger());

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['address' => '10 Downing St', 'city' => 'London', 'country' => 'GB']),
        ], ['allowedZones' => [self::NYC_CIRCLE]]);

        $this->assertSame(['10 Downing St, London, GB'], $geocoder->calls);
        $this->assertCount(1, $flags);
    }

    public function testInvalidZonesDisableTheRule(): void
    {
        $rule = new Geolocation(null, new NullLogger());

        $flags = $rule->evaluate(Fixtures::integration(), [
            $this->at(['lat' => 34.05, 'lon' => -118.24]),
        ], ['allowedZones' => [['type' => 'circle', 'center' => ['lat' => 200, 'lng' => 0], 'radius' => 10]]]);

        $this->assertSame([], $flags);
    }
}
