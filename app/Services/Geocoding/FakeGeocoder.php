<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Str;

/**
 * Deterministic geocoder for local dev (before/without a real key) and tests.
 * Returns one canonical result (most-likely locale, no picker); throws for an
 * "unfindable" sentinel so the failure path can be exercised.
 */
class FakeGeocoder implements Geocoder
{
    /** @var array<string, array{name: string, lat: float, lng: float}> */
    private array $known = [
        'edinburgh' => ['name' => 'Edinburgh, United Kingdom', 'lat' => 55.9533, 'lng' => -3.1883],
        'paris' => ['name' => 'Paris, France', 'lat' => 48.8566, 'lng' => 2.3522],
        'tokyo' => ['name' => 'Tokyo, Japan', 'lat' => 35.6762, 'lng' => 139.6503],
    ];

    public function geocode(string $destination): GeocodeResult
    {
        $key = Str::lower(trim($destination));

        if ($key === '' || str_contains($key, 'unfindable')) {
            throw new GeocodingFailedException("No results for [{$destination}].");
        }

        if (isset($this->known[$key])) {
            $m = $this->known[$key];

            return new GeocodeResult($m['name'], $m['lat'], $m['lng']);
        }

        // Generic deterministic fallback for any other input.
        return new GeocodeResult(Str::title($key).', Testland', 51.5074, -0.1278);
    }

    public function resolvePlace(string $placeId, ?string $sessionToken = null): GeocodeResult
    {
        // Recognizes exactly the `fake-*` ids FakePlaceAutocomplete suggests;
        // anything else throws so the fallback-to-text path can be exercised.
        $key = Str::after($placeId, 'fake-');

        if ($placeId === "fake-{$key}" && isset($this->known[$key])) {
            $m = $this->known[$key];

            return new GeocodeResult($m['name'], $m['lat'], $m['lng']);
        }

        throw new GeocodingFailedException("No place for id [{$placeId}].");
    }
}
