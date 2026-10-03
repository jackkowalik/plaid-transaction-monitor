<?php
declare(strict_types=1);

namespace PlaidMonitor\Rules\Geocoding;

use PlaidMonitor\Http\HttpClient;
use PlaidMonitor\Http\HttpException;
use Psr\Log\LoggerInterface;

class GoogleGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger
    ) {
    }

    public function geocode(string $address): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query(['address' => $address, 'key' => $this->apiKey]);

        try {
            $response = $this->http->request('GET', $url, ['Accept' => 'application/json'], null, 15);
        } catch (HttpException $e) {
            $this->logger->warning('Geocoding request failed', ['error' => $e->getMessage()]);
            return null;
        }

        $data = $response->json();
        $status = $data['status'] ?? null;

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            $this->logger->warning('Geocoding returned an error', [
                'http_status' => $response->status,
                'status'      => $status,
            ]);
            return null;
        }

        $location = $data['results'][0]['geometry']['location'] ?? null;
        if (!isset($location['lat'], $location['lng'])) {
            return null;
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }
}
