<?php

use App\Models\Trip;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('services.google.geocoding_key', 'test-key'));

it('backfills active trips missing a zone and leaves existing ones untouched', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'Europe/London'])]);

    $needs = Trip::factory()->create(['destination_timezone' => null, 'status' => Trip::STATUS_ACTIVE]);
    $has = Trip::factory()->create(['destination_timezone' => 'Asia/Tokyo', 'status' => Trip::STATUS_ACTIVE]);

    $this->artisan('trips:backfill-timezones')->assertExitCode(0);

    expect($needs->fresh()->destination_timezone)->toBe('Europe/London') // filled
        ->and($has->fresh()->destination_timezone)->toBe('Asia/Tokyo');   // idempotent — untouched
});

it('leaves a trip null when resolution fails', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS'])]);

    $trip = Trip::factory()->create(['destination_timezone' => null, 'status' => Trip::STATUS_ACTIVE]);

    $this->artisan('trips:backfill-timezones')->assertExitCode(0);

    expect($trip->fresh()->destination_timezone)->toBeNull();
});

it('fails fast when no Google key is configured', function () {
    config()->set('services.google.geocoding_key', '');

    $this->artisan('trips:backfill-timezones')->assertExitCode(1);
});
