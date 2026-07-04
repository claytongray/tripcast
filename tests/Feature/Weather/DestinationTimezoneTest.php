<?php

use App\Services\Weather\DestinationTimezone;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('services.google.geocoding_key', 'test-key'));

it('resolves an IANA zone from coordinates', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK', 'timeZoneId' => 'America/New_York',
    ])]);

    expect(app(DestinationTimezone::class)->resolve(39.8467, -75.7116))->toBe('America/New_York');
});

it('returns null on a non-OK status (never a fabricated zone)', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS'])]);

    expect(app(DestinationTimezone::class)->resolve(0.0, 0.0))->toBeNull();
});

it('caches a resolved zone — one HTTP call for repeat coordinates', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'timeZoneId' => 'Europe/London'])]);

    $svc = app(DestinationTimezone::class);
    $svc->resolve(51.5074, -0.1278);
    $svc->resolve(51.5074, -0.1278);

    Http::assertSentCount(1);
});
