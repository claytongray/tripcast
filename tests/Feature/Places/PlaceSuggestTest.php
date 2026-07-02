<?php

use App\Services\Geocoding\FakePlaceAutocomplete;
use App\Services\Geocoding\PlaceAutocomplete;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

// Story 9.4 (FR-22) — the proxied suggest endpoint: always 200 with a
// (possibly empty) list for valid input; the key never reaches the browser.

beforeEach(function () {
    app()->bind(PlaceAutocomplete::class, FakePlaceAutocomplete::class);
});

it('returns suggestions for a known query', function () {
    getJson(route('places.suggest', ['q' => 'edin', 'token' => 'session-1']))
        ->assertOk()
        ->assertJson(['suggestions' => [['place_id' => 'fake-edinburgh', 'label' => 'Edinburgh, United Kingdom']]]);
});

it('returns an empty list for an unknown query', function () {
    getJson(route('places.suggest', ['q' => 'zzzz', 'token' => 'session-1']))
        ->assertOk()
        ->assertExactJson(['suggestions' => []]);
});

it('rejects a query under two characters', function () {
    getJson(route('places.suggest', ['q' => 'e', 'token' => 'session-1']))
        ->assertUnprocessable();
});

it('rejects a missing session token', function () {
    getJson(route('places.suggest', ['q' => 'edin']))
        ->assertUnprocessable();
});

it('is throttled per IP on the route middleware', function () {
    $route = Route::getRoutes()->getByName('places.suggest');

    expect($route->gatherMiddleware())->toContain('throttle:120,1');
});
