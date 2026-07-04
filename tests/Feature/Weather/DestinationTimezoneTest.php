<?php

use App\Services\Weather\DestinationTimezone;
use Illuminate\Support\Facades\Http;

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
