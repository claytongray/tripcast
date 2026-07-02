<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google Maps Geocoding API adapter (AD-1). The only place the vendor HTTP
 * contract appears. The first result is the most-likely locale (Google ranks
 * them) — no "did you mean?" picker (AC2).
 */
class GoogleGeocoder implements Geocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const PLACES_ENDPOINT = 'https://places.googleapis.com/v1/places';

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

        // Guard the canonical_place_name varchar(255) column against the rare
        // very-long formatted_address (AD-8 stores it once).
        return new GeocodeResult(Str::limit((string) $name, 255, ''), (float) $lat, (float) $lng);
    }

    public function resolvePlace(string $placeId, ?string $sessionToken = null): GeocodeResult
    {
        // Place Details (New), not the Geocoding API: the Details call is what
        // terminates the autocomplete session, so the keystrokes bill as one
        // session (FR-22) — and the placeId resolves exactly.
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => 'formattedAddress,location',
                ])
                ->get(
                    self::PLACES_ENDPOINT.'/'.rawurlencode($placeId),
                    $sessionToken !== null ? ['sessionToken' => $sessionToken] : [],
                );
        } catch (Throwable $e) {
            throw new GeocodingFailedException("Place details request failed for [{$placeId}].", 0, $e);
        }

        if ($response->failed()) {
            throw new GeocodingFailedException("Place details HTTP error [{$response->status()}].");
        }

        $name = $response->json('formattedAddress');
        $lat = $response->json('location.latitude');
        $lng = $response->json('location.longitude');

        if ($name === null || $lat === null || $lng === null) {
            throw new GeocodingFailedException("Place details missing fields for [{$placeId}].");
        }

        return new GeocodeResult(Str::limit((string) $name, 255, ''), (float) $lat, (float) $lng);
    }
}
