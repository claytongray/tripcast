<?php

use App\Services\Geocoding\GeocodingFailedException;
use App\Services\Geocoding\GoogleGeocoder;
use Illuminate\Support\Facades\Http;

// AC2 — maps the top (most-likely) Google result to a GeocodeResult.
it('maps a Google payload to a GeocodeResult', function () {
    Http::fake([
        '*' => Http::response([
            'status' => 'OK',
            'results' => [
                [
                    'formatted_address' => 'Edinburgh, UK',
                    'geometry' => ['location' => ['lat' => 55.9533, 'lng' => -3.1883]],
                ],
                [
                    'formatted_address' => 'Edinburgh, IN, USA',
                    'geometry' => ['location' => ['lat' => 39.35, 'lng' => -85.96]],
                ],
            ],
        ]),
    ]);

    $result = (new GoogleGeocoder('test-key'))->geocode('Edinburgh');

    expect($result->canonicalPlaceName)->toBe('Edinburgh, UK')
        ->and($result->latitude)->toBe(55.9533)
        ->and($result->longitude)->toBe(-3.1883);
});

// AC3 — ZERO_RESULTS becomes a typed failure.
it('throws GeocodingFailedException on ZERO_RESULTS', function () {
    Http::fake(['*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    (new GoogleGeocoder('test-key'))->geocode('asdkjfhqwoeiu');
})->throws(GeocodingFailedException::class);

// AC3 — an HTTP error becomes a typed failure (never leaks the vendor error).
it('throws GeocodingFailedException on an HTTP error', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    (new GoogleGeocoder('test-key'))->geocode('Edinburgh');
})->throws(GeocodingFailedException::class);

// Story 9.4 (FR-22) — resolvePlace uses Place Details (New) so the
// autocomplete session terminates (per-session billing) and the placeId
// resolves exactly.
it('resolves a place id via Place Details with the session token and field mask', function () {
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'formattedAddress' => 'Edinburgh, UK',
            'location' => ['latitude' => 55.9533, 'longitude' => -3.1883],
        ]),
    ]);

    $result = (new GoogleGeocoder('test-key'))->resolvePlace('ChIJEdinburgh', 'session-1');

    expect($result->canonicalPlaceName)->toBe('Edinburgh, UK')
        ->and($result->latitude)->toBe(55.9533)
        ->and($result->longitude)->toBe(-3.1883);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'places/ChIJEdinburgh')
            && str_contains($request->url(), 'sessionToken=session-1')
            && $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && $request->hasHeader('X-Goog-FieldMask', 'formattedAddress,location');
    });
});

it('throws GeocodingFailedException when place details fails', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'not found'], 404)]);

    (new GoogleGeocoder('test-key'))->resolvePlace('bogus-id', null);
})->throws(GeocodingFailedException::class);

it('throws GeocodingFailedException when place details is missing fields', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['formattedAddress' => 'Somewhere'])]);

    (new GoogleGeocoder('test-key'))->resolvePlace('half-id', null);
})->throws(GeocodingFailedException::class);
