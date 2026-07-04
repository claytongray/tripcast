<?php

use App\Actions\CreateTrip;
use App\Models\Trip;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('services.google.geocoding_key', 'test-key'));

function captureTripDetails(array $overrides = []): array
{
    return array_merge([
        'destination' => 'Kennett Square, PA',
        'canonical_place_name' => 'Kennett Square, PA, USA',
        'latitude' => 39.8467,
        'longitude' => -75.7116,
        'departure_date' => now('America/New_York')->addDays(10)->toDateString(),
        'return_date' => now('America/New_York')->addDays(17)->toDateString(),
    ], $overrides);
}

it('persists a nullable destination_timezone column', function () {
    $trip = Trip::factory()->create(['destination_timezone' => 'Europe/London']);
    expect($trip->fresh()->destination_timezone)->toBe('Europe/London');

    expect(Trip::factory()->create()->destination_timezone)->toBeNull();
});

it('resolves and stores the destination timezone at trip creation', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'America/New_York'])]);

    $trip = app(CreateTrip::class)->handle('traveler@example.com', captureTripDetails());

    expect($trip->fresh()->destination_timezone)->toBe('America/New_York');
});

it('leaves destination_timezone null when resolution fails', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS'])]);

    $trip = app(CreateTrip::class)->handle('traveler2@example.com', captureTripDetails(['latitude' => 0.0, 'longitude' => 0.0]));

    expect($trip->fresh()->destination_timezone)->toBeNull();
});
