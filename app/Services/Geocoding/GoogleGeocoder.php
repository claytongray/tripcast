<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Maps Geocoding API adapter (AD-1). The only place the vendor HTTP
 * contract appears. The first result is the most-likely locale (Google ranks
 * them) — no "did you mean?" picker (AC2).
 */
class GoogleGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(private string $apiKey) {}

    public function geocode(string $destination): GeocodeResult
    {
        try {
            $response = Http::timeout(10)->get(self::ENDPOINT, [
                'address' => $destination,
                'key' => $this->apiKey,
            ]);
        } catch (Throwable $e) {
            throw new GeocodingFailedException("Geocoding request failed for [{$destination}].", 0, $e);
        }

        if ($response->failed()) {
            throw new GeocodingFailedException("Geocoding HTTP error [{$response->status()}].");
        }

        $data = $response->json();
        $status = $data['status'] ?? 'UNKNOWN';
        $results = $data['results'] ?? [];

        if ($status !== 'OK' || $results === []) {
            throw new GeocodingFailedException("Geocoding returned [{$status}] for [{$destination}].");
        }

        $top = $results[0];
        $name = $top['formatted_address'] ?? null;
        $lat = $top['geometry']['location']['lat'] ?? null;
        $lng = $top['geometry']['location']['lng'] ?? null;

        if ($name === null || $lat === null || $lng === null) {
            throw new GeocodingFailedException("Geocoding result missing fields for [{$destination}].");
        }

        return new GeocodeResult($name, (float) $lat, (float) $lng);
    }
}
