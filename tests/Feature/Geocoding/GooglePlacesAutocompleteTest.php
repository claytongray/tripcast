<?php

use App\Services\Geocoding\GooglePlacesAutocomplete;
use App\Services\Geocoding\PlaceSuggestion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Story 9.4 (FR-22) — the autocomplete adapter maps Places API (New)
// predictions to suggestions and swallows every failure to [] (degradation
// is the contract; the field falls back to plain free text).

it('maps placePrediction suggestions to DTOs', function () {
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'suggestions' => [
                ['placePrediction' => ['placeId' => 'ChIJEdinburgh', 'text' => ['text' => 'Edinburgh, UK']]],
                ['placePrediction' => ['placeId' => 'ChIJEdina', 'text' => ['text' => 'Edina, MN, USA']]],
            ],
        ]),
    ]);

    $suggestions = (new GooglePlacesAutocomplete('test-key'))->suggest('edin', 'token-1');

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions[0])->toBeInstanceOf(PlaceSuggestion::class)
        ->and($suggestions[0]->placeId)->toBe('ChIJEdinburgh')
        ->and($suggestions[0]->label)->toBe('Edinburgh, UK');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'places:autocomplete')
            && $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && $request['input'] === 'edin'
            && $request['sessionToken'] === 'token-1'
            && $request['includedPrimaryTypes'] === ['(regions)'];
    });
});

it('returns an empty list on HTTP failure', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'denied'], 403)]);

    expect((new GooglePlacesAutocomplete('test-key'))->suggest('edin', 't'))->toBe([]);
});

it('returns an empty list on a malformed body', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['unexpected' => true])]);

    expect((new GooglePlacesAutocomplete('test-key'))->suggest('edin', 't'))->toBe([]);
});

it('returns an empty list on a connection failure', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect((new GooglePlacesAutocomplete('test-key'))->suggest('edin', 't'))->toBe([]);
});

it('caps suggestions at five', function () {
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'suggestions' => array_map(fn (int $i) => [
                'placePrediction' => ['placeId' => "id-{$i}", 'text' => ['text' => "Place {$i}"]],
            ], range(1, 8)),
        ]),
    ]);

    expect((new GooglePlacesAutocomplete('test-key'))->suggest('pla', 't'))->toHaveCount(5);
});
